<?php

namespace Tests\Integration;

use App\Enums\BusinessType;
use App\Models\Business;
use App\Models\CashSession;
use App\Models\Customer;
use App\Models\Product;
use App\Models\Receivable;
use App\Models\Sale;
use App\Models\User;
use App\Scopes\BranchScope;
use App\Services\AccountingService;
use App\Services\CashSessionService;
use App\Services\ReceivablePaymentService;
use App\Services\SaleCreditService;
use App\Services\SaleService;
use App\Support\TenantContext;
use App\Support\TestingDatabaseGuard;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use InvalidArgumentException;
use PHPUnit\Framework\Attributes\DataProvider;
use RuntimeException;
use Symfony\Component\Process\InputStream;
use Symfony\Component\Process\Process;
use Tests\TestCase;

/**
 * DATA-02: concurrencia real de cobros CxC con conexiones MySQL independientes.
 *
 * Cada prueba trabaja sobre copias vacías y prefijadas (crd_data02_cc_<hex>_*) del esquema
 * aislado, porque los workers necesitan commits reales; las copias se eliminan en tearDown.
 * Solo se ejecuta con DATA02_CONCURRENCY_INTEGRATION=1 dentro del entorno Docker DATA-02.
 */
class ReceivablePaymentConcurrencyTest extends TestCase
{
    private const PREFIX_PATTERN = '/^crd_data02_cc_[a-f0-9]{16}_$/D';

    private string $prefix = '';

    /** @var list<string> */
    private array $createdTables = [];

    /** @var list<Process> */
    private array $processes = [];

    protected function setUp(): void
    {
        parent::setUp();
        if (getenv('DATA02_CONCURRENCY_INTEGRATION') !== '1') {
            $this->markTestSkipped('Requiere DATA02_CONCURRENCY_INTEGRATION=1 en el entorno DATA-02 aislado.');
        }
        $this->assertSame('test-mysql', config('database.connections.mysql.host'));
        $this->assertSame('', config('database.connections.mysql.unix_socket'));
        $this->assertSame('', config('database.connections.mysql.prefix'));
        TestingDatabaseGuard::assertIsolated($this->app);
        config(['scale.post_sale_queue' => false, 'ecf.enabled' => false]);

        $this->prefix = 'crd_data02_cc_'.bin2hex(random_bytes(8)).'_';
        $this->assertMatchesRegularExpression(self::PREFIX_PATTERN, $this->prefix);
        $tables = array_map(fn (object $row): string => $row->name, DB::select(
            "SELECT table_name AS name FROM information_schema.tables
             WHERE table_schema = DATABASE() AND table_type = 'BASE TABLE' ORDER BY table_name"
        ));
        foreach ($tables as $source) {
            if ($source === 'migrations' || str_starts_with($source, 'crd_')) {
                continue;
            }
            DB::statement("CREATE TABLE `{$this->prefix}{$source}` LIKE `{$source}`");
            $this->createdTables[] = $this->prefix.$source;
        }
        config(['database.connections.mysql.prefix' => $this->prefix]);
        DB::purge('mysql');
    }

    protected function tearDown(): void
    {
        try {
            foreach ($this->processes as $process) {
                if ($process->isRunning()) {
                    $process->stop(1);
                }
            }
            while (DB::transactionLevel() > 0) {
                DB::rollBack();
            }
            if ($this->createdTables !== []) {
                config(['database.connections.mysql.prefix' => '']);
                DB::purge('mysql');
                TestingDatabaseGuard::assertIsolated($this->app);
                foreach (array_reverse($this->createdTables) as $table) {
                    if (preg_match(self::PREFIX_PATTERN, substr($table, 0, strlen($this->prefix))) !== 1) {
                        throw new RuntimeException("Tabla fuera del prefijo de prueba: {$table}");
                    }
                    DB::statement("DROP TABLE `{$table}`");
                }
            }
        } finally {
            parent::tearDown();
        }
    }

    public static function receivableKinds(): array
    {
        return ['venta a crédito' => ['sale'], 'CxC manual' => ['standalone']];
    }

    #[DataProvider('receivableKinds')]
    public function test_a_payment_waiting_on_the_lock_cannot_overpay_after_the_holder_commits(string $kind): void
    {
        $tenant = $this->tenant('Sobrepago');
        $receivable = $this->receivable($tenant, $kind);

        $this->hold(fn () => $receivable->registerPayment(100, 'transferencia', requestKey: 'holder-1'));
        $worker = $this->collectWorker($tenant, $receivable, 100, 'transferencia', 'waiter-1');
        $this->go($worker);
        $this->awaitBlocked($worker);
        DB::commit();

        $result = $this->workerResult($worker);
        $this->assertSame('error', $result['status'], 'El cobro en espera no debe superar el saldo: '.json_encode($result));
        $this->assertSame(InvalidArgumentException::class, $result['exception']);
        $this->assertStringContainsString('supera el saldo', $result['message']);
        $this->assertLedger($receivable, paid: '100.00', payments: 1);
    }

    #[DataProvider('receivableKinds')]
    public function test_payments_within_the_balance_serialize_without_lost_updates(string $kind): void
    {
        $tenant = $this->tenant('Serialización');
        $receivable = $this->receivable($tenant, $kind);

        $this->hold(fn () => $receivable->registerPayment(60, 'transferencia', requestKey: 'holder-60'));
        $worker = $this->collectWorker($tenant, $receivable, 58, 'tarjeta', 'waiter-58');
        $this->go($worker);
        $this->awaitBlocked($worker);
        DB::commit();

        $this->assertSame('ok', $this->workerResult($worker)['status']);
        $this->assertLedger($receivable, paid: '118.00', payments: 2);
        $this->assertSame('pagada', $this->fresh($receivable)->status);
    }

    #[DataProvider('receivableKinds')]
    public function test_simultaneous_workers_racing_for_the_last_balance_admit_exactly_one(string $kind): void
    {
        $tenant = $this->tenant('Carrera');
        for ($round = 1; $round <= 3; $round++) {
            $receivable = $this->receivable($tenant, $kind);
            $first = $this->collectWorker($tenant, $receivable, 100, 'transferencia', "race-{$round}-a");
            $second = $this->collectWorker($tenant, $receivable, 100, 'transferencia', "race-{$round}-b");
            $this->go($first);
            $this->go($second);
            $statuses = [$this->workerResult($first)['status'], $this->workerResult($second)['status']];
            sort($statuses);
            $this->assertSame(['error', 'ok'], $statuses, "Ronda {$round}: exactamente un cobro debe ganar.");
            $this->assertLedger($receivable, paid: '100.00', payments: 1);
        }
    }

    public function test_a_concurrent_retry_with_the_same_key_returns_the_original_cash_payment(): void
    {
        $tenant = $this->tenant('Reintento');
        $session = $this->openCashSession($tenant);
        $receivable = $this->receivable($tenant, 'sale');

        $payment = $this->hold(fn () => $receivable->registerPayment(50, 'efectivo', requestKey: 'retry-key'));
        $worker = $this->collectWorker($tenant, $receivable, 50, 'efectivo', 'retry-key');
        $this->go($worker);
        $this->awaitBlocked($worker);
        DB::commit();

        $result = $this->workerResult($worker);
        $this->assertSame('ok', $result['status'], json_encode($result));
        $this->assertSame((string) $payment->id, $result['payment_id']);
        $this->assertLedger($receivable, paid: '50.00', payments: 1);
        $this->assertSame(1, DB::table('cash_session_movements')->where('cash_session_id', $session->id)->count());
    }

    public function test_a_concurrent_retry_with_the_same_key_and_different_data_is_rejected(): void
    {
        $tenant = $this->tenant('Conflicto');
        $receivable = $this->receivable($tenant, 'sale');

        $this->hold(fn () => $receivable->registerPayment(50, 'transferencia', requestKey: 'conflict-key'));
        $worker = $this->collectWorker($tenant, $receivable, 40, 'transferencia', 'conflict-key');
        $this->go($worker);
        $this->awaitBlocked($worker);
        DB::commit();

        $result = $this->workerResult($worker);
        $this->assertSame('error', $result['status']);
        $this->assertSame(ValidationException::class, $result['exception']);
        $this->assertLedger($receivable, paid: '50.00', payments: 1);
    }

    public function test_a_rolled_back_holder_releases_its_key_and_balance(): void
    {
        $tenant = $this->tenant('Rollback');
        $receivable = $this->receivable($tenant, 'sale');

        $held = $this->hold(fn () => $receivable->registerPayment(100, 'transferencia', requestKey: 'released-key'));
        $worker = $this->collectWorker($tenant, $receivable, 100, 'transferencia', 'released-key');
        $this->go($worker);
        $this->awaitBlocked($worker);
        DB::rollBack();

        $result = $this->workerResult($worker);
        $this->assertSame('ok', $result['status'], json_encode($result));
        $this->assertNotSame((string) $held->id, $result['payment_id']);
        $this->assertLedger($receivable, paid: '100.00', payments: 1);
    }

    public function test_a_lock_wait_timeout_leaves_no_partial_effects(): void
    {
        $tenant = $this->tenant('Timeout');
        $session = $this->openCashSession($tenant);
        $receivable = $this->receivable($tenant, 'sale');

        $this->hold(fn () => $receivable->registerPayment(30, 'efectivo', requestKey: 'timeout-holder'));
        $worker = $this->collectWorker($tenant, $receivable, 30, 'efectivo', 'timeout-waiter', lockWaitTimeout: 1);
        $this->go($worker);
        $result = $this->workerResult($worker);
        DB::commit();

        $this->assertSame('error', $result['status'], json_encode($result));
        $this->assertSame(1205, $result['mysql_code'], json_encode($result));
        $this->assertSame(0, DB::table('receivable_payments')->where('request_key', 'timeout-waiter')->count());
        $this->assertSame(1, DB::table('cash_session_movements')->where('cash_session_id', $session->id)->count());
        $this->assertLedger($receivable, paid: '30.00', payments: 1);
    }

    public function test_contended_cash_payments_match_cash_movements_and_the_closing_total(): void
    {
        $tenant = $this->tenant('Caja');
        $session = $this->openCashSession($tenant);
        $receivable = $this->receivable($tenant, 'sale');

        $this->hold(fn () => $receivable->registerPayment(50, 'efectivo', requestKey: 'cash-a'));
        $worker = $this->collectWorker($tenant, $receivable, 50, 'efectivo', 'cash-b');
        $this->go($worker);
        $this->awaitBlocked($worker);
        DB::commit();

        $this->assertSame('ok', $this->workerResult($worker)['status']);
        $this->assertLedger($receivable, paid: '100.00', payments: 2);
        $movements = DB::table('cash_session_movements')->where('cash_session_id', $session->id)->get();
        $this->assertCount(2, $movements);
        $this->assertSame(['in'], $movements->pluck('direction')->unique()->values()->all());
        $this->assertSame(10000, $movements->sum(fn ($row) => SaleCreditService::cents($row->amount)));

        $closed = app(CashSessionService::class)->close($session, 110);
        $this->assertSame('110.00', (string) $closed->expected_amount);
        $this->assertSame('0.00', (string) $closed->difference);
    }

    public static function closeOrders(): array
    {
        return ['cobro primero' => ['payment-first'], 'cierre primero' => ['close-first']];
    }

    #[DataProvider('closeOrders')]
    public function test_cash_closing_and_cash_payment_serialize_on_the_session_lock(string $order): void
    {
        $tenant = $this->tenant('Cierre');
        $session = $this->openCashSession($tenant);
        $receivable = $this->receivable($tenant, 'sale');

        if ($order === 'payment-first') {
            $this->hold(fn () => $receivable->registerPayment(50, 'efectivo', requestKey: 'before-close'));
            $worker = $this->worker($tenant, ['op' => 'close', 'cash_session_id' => $session->id, 'closing_amount' => 60]);
        } else {
            $this->hold(fn () => app(CashSessionService::class)->close($session, 10));
            $worker = $this->collectWorker($tenant, $receivable, 50, 'efectivo', 'after-close');
        }
        $this->go($worker);
        $this->awaitBlocked($worker);
        DB::commit();
        $result = $this->workerResult($worker);

        if ($order === 'payment-first') {
            $this->assertSame('ok', $result['status'], json_encode($result));
            $this->assertSame('60.00', $result['expected_amount']);
            $this->assertLedger($receivable, paid: '50.00', payments: 1);
        } else {
            $this->assertSame('error', $result['status']);
            $this->assertStringContainsString('No hay una caja abierta', $result['message']);
            $this->assertLedger($receivable, paid: '0.00', payments: 0);
            $this->assertSame(0, DB::table('cash_session_movements')->where('cash_session_id', $session->id)->count());
        }
    }

    public function test_a_payment_cannot_be_reversed_twice_concurrently(): void
    {
        $tenant = $this->tenant('Anulación');
        $receivable = $this->receivable($tenant, 'sale');
        $payment = $receivable->registerPayment(50, 'transferencia', requestKey: 'to-reverse');

        $this->hold(fn () => app(ReceivablePaymentService::class)->reverse($payment, 'Error de digitación', 'reverse-a'));
        $worker = $this->worker($tenant, [
            'op' => 'reverse', 'payment_id' => $payment->id, 'reason' => 'Duplicado', 'request_key' => 'reverse-b',
        ]);
        $this->go($worker);
        $this->awaitBlocked($worker);
        DB::commit();

        $result = $this->workerResult($worker);
        $this->assertSame('error', $result['status']);
        $this->assertStringContainsString('ya fue anulado', $result['message']);
        $this->assertLedger($receivable, paid: '0.00', payments: 1, reversals: 1);
    }

    public function test_tenants_do_not_block_each_other_and_share_request_keys_safely(): void
    {
        $other = $this->tenant('Empresa B');
        $otherReceivable = $this->receivable($other, 'sale');
        $tenant = $this->tenant('Empresa A');
        $receivable = $this->receivable($tenant, 'sale');

        $this->hold(fn () => $receivable->registerPayment(20, 'transferencia', requestKey: 'shared-key'));
        $worker = $this->collectWorker($other, $otherReceivable, 20, 'transferencia', 'shared-key');
        $this->go($worker);
        $blockedOn = null;
        if ($this->line($worker, 'RESULT:', 10) === null) {
            $blockedOn = DB::selectOne(
                'SELECT COMMAND AS command, TIME AS seconds, STATE AS state, INFO AS info FROM information_schema.processlist WHERE ID = ?',
                [$worker['connection']]
            );
        }
        DB::commit();
        $result = $this->workerResult($worker);

        $this->assertSame('ok', $result['status'], json_encode($result));
        $this->assertLedger($receivable, paid: '20.00', payments: 1);
        $this->assertLedger($otherReceivable, paid: '20.00', payments: 1);
        $this->assertNull($blockedOn, 'La empresa B esperó un bloqueo de la empresa A: '.json_encode($blockedOn));
    }

    public function test_a_worker_cannot_collect_another_tenants_receivable(): void
    {
        $owner = $this->tenant('Dueña');
        $receivable = $this->receivable($owner, 'sale');
        $intruder = $this->tenant('Intrusa');

        $worker = $this->collectWorker($intruder, $receivable, 10, 'transferencia', 'foreign-key');
        $this->go($worker);
        $result = $this->workerResult($worker);

        $this->assertSame('error', $result['status'], json_encode($result));
        $this->enter($owner);
        $this->assertLedger($receivable, paid: '0.00', payments: 0);
        $this->assertSame(0, DB::table('receivable_payments')->where('business_id', $intruder['business']->id)->count());
    }

    private function tenant(string $name): array
    {
        $user = User::factory()->create();
        $business = Business::withoutEvents(fn () => Business::create([
            'name' => 'DATA-02 '.$name,
            'slug' => 'data02-cc-'.Str::uuid(),
            'type' => BusinessType::Colmado,
            'owner_id' => $user->id,
            'is_active' => true,
        ]));
        $user->businesses()->attach($business->id, ['role' => 'owner', 'is_active' => true]);
        AccountingService::createDefaultAccounts($business);
        $tenant = compact('user', 'business');
        $this->enter($tenant);
        $tenant['customer'] = Customer::create(['name' => 'Cliente '.$name, 'is_active' => true]);
        $tenant['product'] = Product::forceCreate([
            'business_id' => $business->id, 'name' => 'Producto '.$name,
            'price' => 100, 'cost' => 30, 'stock' => 100, 'min_stock' => 0,
            'is_active' => true, 'track_stock' => true, 'inventory_behavior' => 'deduct_on_sale',
        ]);

        return $tenant;
    }

    private function enter(array $tenant): void
    {
        $this->actingAs($tenant['user']);
        app()->instance('currentBusinessId', $tenant['business']->id);
        app()->instance('currentBusiness', $tenant['business']);
        session(['current_business_id' => $tenant['business']->id]);
    }

    /** Crea una CxC de RD$ 118.00 confirmada (venta a crédito o manual). */
    private function receivable(array $tenant, string $kind): Receivable
    {
        $this->enter($tenant);
        if ($kind === 'sale') {
            $sale = app(SaleService::class)->create([
                'idempotency_key' => (string) Str::uuid(), 'business_id' => $tenant['business']->id,
                'user_id' => $tenant['user']->id, 'customer_id' => $tenant['customer']->id,
                'payment_method' => 'credito', 'tax_rate' => 18,
                'items' => [['product_id' => $tenant['product']->id, 'quantity' => 1, 'unit_price' => 100]],
            ]);

            return app(SaleCreditService::class)->assertReceivable($sale);
        }

        return Receivable::create([
            'customer_id' => $tenant['customer']->id,
            'description' => 'CxC manual de concurrencia',
            'amount' => 118, 'paid_amount' => 0, 'balance' => 118,
            'due_date' => today()->addDays(7)->toDateString(), 'status' => 'pendiente',
        ]);
    }

    private function openCashSession(array $tenant): CashSession
    {
        $session = new CashSession;
        $session->forceFill([
            'id' => (string) Str::uuid(),
            'business_id' => $tenant['business']->id,
            'user_id' => $tenant['user']->id,
            'opening_amount' => 10,
            'total_sales_cash' => 0,
            'total_expenses_cash' => 0,
            'status' => CashSession::STATUS_OPEN,
            'opened_at' => now()->subMinute(),
        ])->saveQuietly();

        return $session;
    }

    /** Ejecuta la operación dentro de una transacción externa que retiene los bloqueos hasta commit/rollBack. */
    private function hold(callable $operation): mixed
    {
        // Igual que en producción: el servicio abre la transacción en READ COMMITTED.
        DB::unprepared('SET TRANSACTION ISOLATION LEVEL READ COMMITTED');
        DB::beginTransaction();
        try {
            return $operation();
        } catch (\Throwable $exception) {
            DB::rollBack();
            throw $exception;
        }
    }

    private function collectWorker(array $tenant, Receivable $receivable, float $amount, string $method, string $key, int $lockWaitTimeout = 20): array
    {
        return $this->worker($tenant, [
            'op' => 'collect', 'receivable_id' => $receivable->id, 'amount' => $amount,
            'method' => $method, 'request_key' => $key, 'lock_wait_timeout' => $lockWaitTimeout,
        ]);
    }

    private function worker(array $tenant, array $command): array
    {
        $input = new InputStream;
        $process = new Process([PHP_BINARY, base_path('tests/Fixtures/receivable-payment-concurrent-worker.php')], base_path(), [
            'DATA02_CONCURRENCY_INTEGRATION' => '1',
            'DATA02_CC_PREFIX' => $this->prefix,
            'DATA02_CC_NOW' => (string) now()->timestamp,
            'DATA02_CC_COMMAND' => json_encode($command + [
                'user_id' => $tenant['user']->id, 'business_id' => $tenant['business']->id,
            ], JSON_THROW_ON_ERROR),
            'CACHE_STORE' => 'array', 'SESSION_DRIVER' => 'array', 'QUEUE_CONNECTION' => 'sync',
            'SCALE_POST_SALE_QUEUE' => 'false', 'ECF_ENABLED' => 'false', 'LOG_CHANNEL' => 'stderr',
        ], $input, 60);
        $this->processes[] = $process;
        $process->start();
        $worker = ['process' => $process, 'input' => $input];
        $startedAt = microtime(true);
        $ready = $this->line($worker, 'READY:', 30);
        if ($ready === null || ! ctype_digit($ready)) {
            $diagnostics = [
                'running' => $process->isRunning(), 'exit_code' => $process->getExitCode(),
                'elapsed_s' => round(microtime(true) - $startedAt, 2),
                'stdout' => $process->getOutput(), 'stderr' => $process->getErrorOutput(),
                'processlist' => DB::select('SELECT id, command, time, state, LEFT(info, 200) AS info FROM information_schema.processlist WHERE db = DATABASE() AND id <> CONNECTION_ID()'),
            ];
            $this->fail('El worker no arrancó: '.json_encode($diagnostics, JSON_UNESCAPED_UNICODE | JSON_PARTIAL_OUTPUT_ON_ERROR));
        }
        $worker['connection'] = (int) $ready;

        return $worker;
    }

    private function go(array $worker): void
    {
        $worker['input']->write("start\n");
    }

    /** Confirma con information_schema.processlist que el worker espera un bloqueo FOR UPDATE. */
    private function awaitBlocked(array $worker): void
    {
        $hits = 0;
        $deadline = microtime(true) + 10;
        while (microtime(true) < $deadline) {
            if ($this->line($worker, 'RESULT:', 0) !== null) {
                $this->fail('El worker terminó sin esperar el bloqueo: '.$worker['process']->getOutput());
            }
            $row = DB::selectOne(
                'SELECT COMMAND AS command, INFO AS info FROM information_schema.processlist WHERE ID = ?',
                [$worker['connection']]
            );
            // Laravel usa sentencias preparadas nativas: la espera aparece como COMMAND='Execute'.
            $waiting = $row && in_array($row->command, ['Query', 'Execute'], true)
                && stripos((string) $row->info, 'for update') !== false;
            $hits = $waiting ? $hits + 1 : 0;
            if ($hits >= 3) {
                return;
            }
            usleep(100_000);
        }
        $this->fail('El worker no quedó esperando un bloqueo FOR UPDATE: '.json_encode($row ?? null));
    }

    private function workerResult(array $worker, float $timeout = 45): array
    {
        $json = $this->line($worker, 'RESULT:', $timeout);
        if ($json === null) {
            $this->fail('El worker no devolvió resultado: '.$worker['process']->getErrorOutput());
        }
        $worker['process']->wait();
        $this->assertSame(0, $worker['process']->getExitCode(), $worker['process']->getErrorOutput());

        return json_decode($json, true, flags: JSON_THROW_ON_ERROR);
    }

    private function line(array $worker, string $prefix, float $timeout): ?string
    {
        $deadline = microtime(true) + $timeout;
        do {
            $running = $worker['process']->isRunning();
            foreach (explode("\n", $worker['process']->getOutput()) as $line) {
                if (str_starts_with($line, $prefix)) {
                    return substr($line, strlen($prefix));
                }
            }
            if (! $running) {
                return null;
            }
            usleep(20_000);
        } while (microtime(true) < $deadline);

        return null;
    }

    private function fresh(Receivable $receivable): Receivable
    {
        return Receivable::withoutBusinessScope()->withTrashed()->findOrFail($receivable->id);
    }

    /** Invariantes: saldo = importe - (cobros - anulados), sin duplicados, asientos y caja 1:1. */
    private function assertLedger(Receivable $receivable, string $paid, int $payments, int $reversals = 0): void
    {
        $receivable = $this->fresh($receivable);
        $businessId = (string) $receivable->business_id;
        $rows = DB::table('receivable_payments')->where('business_id', $businessId)
            ->where('receivable_id', $receivable->id)->get();
        $reversalRows = DB::table('receivable_payment_reversals')->where('business_id', $businessId)
            ->whereIn('receivable_payment_id', $rows->pluck('id'))->get();
        $this->assertCount($payments, $rows, 'Cobros registrados');
        $this->assertCount($reversals, $reversalRows, 'Anulaciones registradas');
        $this->assertSame($rows->count(), $rows->pluck('request_key')->unique()->count(), 'Claves de cobro duplicadas');
        $this->assertSame($reversalRows->count(), $reversalRows->pluck('receivable_payment_id')->unique()->count());

        $reversedIds = $reversalRows->pluck('receivable_payment_id')->map(fn ($id) => (string) $id)->all();
        $paidCents = $rows->reject(fn ($row) => in_array((string) $row->id, $reversedIds, true))
            ->sum(fn ($row) => SaleCreditService::cents($row->amount));
        $amountCents = SaleCreditService::cents($receivable->amount);
        $this->assertSame(SaleCreditService::cents($paid), $paidCents, 'Suma de cobros vigentes');
        $this->assertSame($paidCents, SaleCreditService::cents($receivable->paid_amount), 'paid_amount persistido');
        $this->assertLessThanOrEqual($amountCents, $paidCents, 'Sobrepago persistido');
        $this->assertSame($amountCents - $paidCents, SaleCreditService::cents($receivable->balance), 'Saldo persistido');
        $this->assertSame(
            $paidCents === $amountCents ? 'pagada' : ($paidCents > 0 ? 'parcial' : $receivable->status),
            $receivable->status
        );

        foreach ([['receivable_payment', $rows], ['receivable_payment_reversal', $reversalRows]] as [$type, $sources]) {
            foreach ($sources as $source) {
                $entries = DB::table('journal_entries')->where('business_id', $businessId)
                    ->where('source_type', $type)->where('source_id', $source->id)->get();
                $this->assertCount(1, $entries, "Asiento único para {$type} {$source->id}");
                $this->assertSame(
                    SaleCreditService::cents($entries[0]->total_debit),
                    SaleCreditService::cents($entries[0]->total_credit),
                    'Asiento cuadrado'
                );
                $this->assertSame(2, DB::table('journal_entry_lines')->where('journal_entry_id', $entries[0]->id)->count());
            }
        }

        foreach ($rows as $row) {
            $movements = DB::table('cash_session_movements')->where('business_id', $businessId)
                ->where('source_type', 'receivable_payment')->where('source_id', $row->id)->get();
            if ($row->payment_method === 'efectivo') {
                $this->assertCount(1, $movements, 'Movimiento de caja único por cobro en efectivo');
                $this->assertSame('in', $movements[0]->direction);
                $this->assertSame(SaleCreditService::cents($row->amount), SaleCreditService::cents($movements[0]->amount));
                $this->assertSame((string) $row->cash_session_id, (string) $movements[0]->cash_session_id);
            } else {
                $this->assertCount(0, $movements, 'Cobro no monetario sin movimiento de caja');
            }
        }

        if ($receivable->sale_id !== null) {
            TenantContext::run($businessId, function () use ($receivable, $businessId): void {
                $sale = Sale::withoutBusinessScope()->withoutGlobalScope(BranchScope::class)
                    ->where('business_id', $businessId)->findOrFail($receivable->sale_id);
                app(SaleCreditService::class)->assertReceivable($sale);
            });
        }
    }
}
