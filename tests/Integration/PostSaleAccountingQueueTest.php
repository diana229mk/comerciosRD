<?php

namespace Tests\Integration;

use App\Contracts\RecordsSale;
use App\Enums\BusinessType;
use App\Jobs\PostSaleAccountingJob;
use App\Models\Account;
use App\Models\AccountingOutbox;
use App\Models\Branch;
use App\Models\Business;
use App\Models\Customer;
use App\Models\InventoryMovement;
use App\Models\JournalEntry;
use App\Models\JournalEntryLine;
use App\Models\Product;
use App\Models\Receivable;
use App\Models\Sale;
use App\Models\SaleItem;
use App\Models\SalesDaily;
use App\Models\User;
use App\Services\AccountingOutboxService;
use App\Services\AccountingService;
use App\Services\AuthContextResolver;
use App\Services\CashSessionService;
use App\Services\SaleCreditService;
use App\Services\SaleFinancialIntegrity;
use App\Services\SaleService;
use App\Support\TestingDatabaseGuard;
use Illuminate\Database\QueryException;
use Illuminate\Queue\RedisQueue;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use PHPUnit\Framework\Attributes\DataProvider;
use Symfony\Component\Process\InputStream;
use Symfony\Component\Process\Process;
use Tests\TestCase;

class PostSaleAccountingQueueTest extends TestCase
{
    private array $createdTables = [];

    private array $workers = [];

    private string $prefix = '';

    private string $salesQueue = '';

    private string $reportsQueue = '';

    private string $defaultQueue = '';

    private ?RedisQueue $queue = null;

    protected function setUp(): void
    {
        parent::setUp();
        $this->assertSame('1', getenv('ACCOUNTING_QUEUE_INTEGRATION'));
        $this->assertSame('test-mysql', config('database.connections.mysql.host'));
        $this->assertSame('', config('database.connections.mysql.unix_socket'));
        $this->assertSame('', config('database.connections.mysql.prefix'));
        $this->assertSame('test-redis', config('database.redis.default.host'));
        $this->assertEmpty(config('database.redis.default.url'));
        $this->assertSame('6379', (string) config('database.redis.default.port'));
        $this->assertSame('15', (string) config('database.redis.default.database'));
        TestingDatabaseGuard::assertIsolated($this->app);

        $id = bin2hex(random_bytes(8));
        $this->prefix = 'crd_accounting_test_'.$id.'_';
        $this->salesQueue = 'sales_'.$id;
        $this->reportsQueue = 'reports_'.$id;
        $this->defaultQueue = 'default_'.$id;
        config([
            'database.redis.options.prefix' => $this->prefix,
            'queue.default' => 'redis',
            'queue.connections.redis.connection' => 'default',
            'queue.connections.redis.queue' => $this->defaultQueue,
            'queue.connections.redis.retry_after' => 180,
            'scale.queues.sales' => $this->salesQueue,
            'scale.queues.reports' => $this->reportsQueue,
            'scale.post_sale_queue' => true,
            'ecf.enabled' => false,
        ]);
        $this->app['redis']->purge('default');
        $this->assertNotFalse($this->app['redis']->connection()->ping());
        $this->queue = $this->app['queue']->connection('redis');

        // Los workers necesitan commits reales; solo se escriben copias vacías propias.
        foreach ([
            'users', 'businesses', 'user_business', 'branches', 'accounts',
            'products', 'product_batches', 'sales', 'sale_items', 'sale_item_batches',
            'inventory_movements', 'sales_daily', 'cash_sessions',
            'journal_entries', 'journal_entry_lines', 'failed_jobs', 'accounting_outbox', 'accounting_sequences',
            'customers', 'receivables', 'receivable_payments', 'sale_checkout_requests',
        ] as $source) {
            $table = $this->prefix.$source;
            DB::statement("CREATE TABLE `{$table}` LIKE `{$source}`");
            $this->createdTables[] = $table;
        }
        // LIKE does not copy foreign keys; these constraints must only reference our own copies.
        DB::statement("ALTER TABLE `{$this->prefix}receivables`
            ADD CONSTRAINT `f2_{$id}_receipt_source` FOREIGN KEY (business_id, sale_id)
            REFERENCES `{$this->prefix}sales` (business_id, id)");
        DB::statement("ALTER TABLE `{$this->prefix}sale_checkout_requests`
            ADD CONSTRAINT `f2_{$id}_request_source` FOREIGN KEY (business_id, sale_id)
            REFERENCES `{$this->prefix}sales` (business_id, id)");
        config(['database.connections.mysql.prefix' => $this->prefix]);
        DB::purge('mysql');
    }

    protected function tearDown(): void
    {
        try {
            foreach ($this->workers as $worker) {
                if ($worker->isRunning()) {
                    $worker->stop(1);
                }
            }
            if ($this->createdTables !== []) {
                while (DB::transactionLevel() > 0) {
                    DB::rollBack();
                }
            }
            if ($this->queue !== null) {
                foreach ([$this->salesQueue, $this->reportsQueue, $this->defaultQueue] as $queue) {
                    $this->queue->clear($queue);
                }
            }
            if ($this->createdTables !== []) {
                config(['database.connections.mysql.prefix' => '']);
                DB::purge('mysql');
                TestingDatabaseGuard::assertIsolated($this->app);
                foreach (array_reverse($this->createdTables) as $table) {
                    DB::statement("DROP TABLE `{$table}`");
                }
            }
        } finally {
            $this->travelBack();
            parent::tearDown();
        }
    }

    public function test_sale_commits_before_dispatch_and_a_real_worker_posts_it_without_repeating_checkout(): void
    {
        $fixture = $this->saleContext();
        $cash = app(CashSessionService::class)->open(100);
        DB::beginTransaction();

        $sale = $this->createSale($fixture);
        $this->assertSame(0, $this->queue->size($this->salesQueue));
        $this->assertSame(0, JournalEntry::withoutBusinessScope()->count());
        $this->assertSame('pending', AccountingOutbox::withoutBusinessScope()->sole()->status);
        DB::commit();

        $this->assertSame(1, $this->queue->size($this->salesQueue));
        $this->assertSame(0, $this->queue->size($this->defaultQueue));
        $this->assertSame(59.0, (float) $sale->fresh()->total);
        $this->assertSame(1, $sale->items()->count());
        $this->assertSame(19.0, (float) $fixture['product']->fresh()->stock);
        $this->assertSame(59.0, (float) $cash->fresh()->total_sales_cash);

        $this->runWorker();

        $this->assertPostedOnce($sale);
        $this->assertSame(0, $this->queue->size($this->salesQueue));
        $this->assertSame(19.0, (float) $fixture['product']->fresh()->stock);
        $this->assertSame(59.0, (float) $cash->fresh()->total_sales_cash);
        $this->assertSame(1, InventoryMovement::withoutBusinessScope()
            ->where('reference_id', $sale->id)->count());
        $this->assertSame(1, (int) SalesDaily::withoutBusinessScope()->sole()->tickets);
    }

    #[DataProvider('creditCompetitors')]
    public function test_credit_checkouts_contend_on_a_real_mysql_request_lock(string $mode): void
    {
        $fixture = $this->saleContext();
        $command = $this->creditCommand($fixture);
        $input = new InputStream;
        DB::beginTransaction();
        try {
            $sale = app(SaleService::class)->create($command);
            $worker = $this->startCreditWorker($command, $fixture, $mode, $input, $sale);
            $this->awaitMarker($worker, 'READY:');
            preg_match('/READY:(\d+)/', $worker->getOutput(), $connection);
            $this->assertNotSame((int) $connection[1], (int) DB::selectOne('SELECT CONNECTION_ID() AS id')->id);
            $input->write("start\n");
            $this->awaitMarker($worker, 'BLOCKED');
            $this->assertSame(0, $this->queue->size($this->salesQueue));
            DB::commit();
            $input->write("retry\n");
            $input->close();
            $this->assertSame(0, $worker->wait(), $worker->getErrorOutput().$worker->getOutput());
            $marker = match ($mode) {
                'conflict' => 'CONFLICT',
                'unique-source' => 'UNIQUE_REJECTED',
                default => 'SALE:'.$sale->id,
            };
            $this->assertStringContainsString($marker, $worker->getOutput());
            $this->assertSame(1, Sale::withoutBusinessScope()->count());
            $this->assertSame(1, Receivable::withoutBusinessScope()->count());
            $this->assertSame(1, DB::table('sale_checkout_requests')->count());
            $this->assertSame(19.0, (float) $fixture['product']->fresh()->stock);
            $this->assertSame(1, (int) SalesDaily::withoutBusinessScope()->sole()->tickets);
            $this->runWorker('drain');
            $this->assertPostedOnce($sale);
        } finally {
            while (DB::transactionLevel() > 0) {
                DB::rollBack();
            }
            $input->close();
        }
    }

    public static function creditCompetitors(): array
    {
        return [['same'], ['conflict'], ['unique-source']];
    }

    #[DataProvider('creditInterruptions')]
    public function test_credit_recovers_after_its_own_process_is_killed(string $mode): void
    {
        $fixture = $this->saleContext();
        $command = $this->creditCommand($fixture);
        $input = new InputStream;
        $worker = $this->startCreditWorker($command, $fixture, $mode, $input);
        $this->awaitMarker($worker, 'READY:');
        preg_match('/READY:(\d+)/', $worker->getOutput(), $connection);
        $this->assertNotSame((int) $connection[1], (int) DB::selectOne('SELECT CONNECTION_ID() AS id')->id);
        $input->write("start\n");
        $this->awaitMarker($worker, $mode === 'before-commit' ? 'CREDIT_UNCOMMITTED' : 'CREDIT_COMMITTED:');
        if ($mode === 'before-commit') {
            $this->assertSame(0, Sale::withoutBusinessScope()->count());
            $this->assertSame(0, Receivable::withoutBusinessScope()->count());
            $this->assertSame(0, DB::table('sale_checkout_requests')->count());
            $this->assertSame(20.0, (float) $fixture['product']->fresh()->stock);
        }
        $worker->signal(SIGKILL);
        $worker->wait();
        $input->close();
        $sale = app(SaleService::class)->create($command);
        if ($mode === 'after-commit') {
            $this->assertStringContainsString('CREDIT_COMMITTED:'.$sale->id, $worker->getOutput());
        }
        $this->assertSame(1, Sale::withoutBusinessScope()->count());
        $this->assertSame(1, Receivable::withoutBusinessScope()->count());
        $this->assertSame(1, DB::table('sale_checkout_requests')->count());
        $this->assertSame(19.0, (float) $fixture['product']->fresh()->stock);
        $this->runWorker('drain');
        $this->assertPostedOnce($sale);
    }

    public static function creditInterruptions(): array
    {
        return [['before-commit'], ['after-commit']];
    }

    public function test_credit_fk_failure_rolls_back_all_effects_on_committed_mysql_copies(): void
    {
        $fixture = $this->saleContext();
        $command = $this->creditCommand($fixture);
        Receivable::creating(function (Receivable $receivable): void {
            $receivable->sale_id = (string) Str::uuid();
        });
        try {
            app(SaleService::class)->create($command);
            $this->fail('The copied source FK must reject a missing sale.');
        } catch (QueryException $exception) {
            $this->assertSame(1452, (int) $exception->errorInfo[1]);
        }
        foreach (['sales', 'sale_items', 'receivables', 'inventory_movements', 'sales_daily',
            'accounting_outbox', 'sale_checkout_requests', 'journal_entries'] as $table) {
            $this->assertSame(0, DB::table($table)->count(), $table);
        }
        $this->assertSame(20.0, (float) $fixture['product']->fresh()->stock);
        $this->assertSame(0, $this->queue->size($this->salesQueue));
    }

    public function test_credit_debt_survives_redis_failure_recovery_and_duplicate_accounting_delivery(): void
    {
        $fixture = $this->saleContext();
        $command = $this->creditCommand($fixture);
        $manager = Queue::getFacadeRoot();
        $broken = \Mockery::mock(\Illuminate\Contracts\Queue\Queue::class);
        $broken->shouldReceive('push')->once()->andThrow(new \RuntimeException('redis unavailable'));
        Queue::partialMock()->shouldReceive('connection')->andReturn($broken);
        try {
            $sale = app(SaleService::class)->create($command);
        } finally {
            Queue::swap($manager);
        }
        $receivable = app(SaleCreditService::class)->assertReceivable($sale);
        $this->assertSame('pending', AccountingOutbox::withoutBusinessScope()->sole()->status);
        $this->assertSame((string) $sale->id, (string) app(SaleService::class)->create($command)->id);
        $this->travel(31)->seconds();
        $this->assertSame(1, app(AccountingOutboxService::class)->dispatchDue()['published']);
        $this->runWorker('drain');
        dispatch(new PostSaleAccountingJob((string) $sale->business_id, (string) $sale->id));
        $this->runWorker('drain');
        $this->assertPostedOnce($sale);
        $this->assertSame(1, Receivable::withoutBusinessScope()->count());
        $this->assertSame($receivable->getAttributes(), $receivable->fresh()->getAttributes());
    }

    #[DataProvider('financialCompetitors')]
    public function test_financial_competitor_waits_for_reconfirmation_and_rejects_a_stale_model(string $operation): void
    {
        $fixture = $this->saleContext();
        $ids = [(string) Str::uuid(), (string) Str::uuid()];
        sort($ids, SORT_STRING);
        $sale = $this->cancelledFinancialSale($fixture, $ids[0]);
        $destination = $this->cancelledFinancialSale($fixture, $ids[1]);
        $item = $sale->items()->sole();
        $input = new InputStream;
        $worker = new Process([PHP_BINARY, base_path('tests/Fixtures/sale-financial-concurrent-worker.php')], base_path(), [
            'ACCOUNTING_QUEUE_INTEGRATION' => '1',
            'F1_TEST_PREFIX' => $this->prefix,
            'F1_TEST_USER' => $fixture['user']->id,
            'F1_TEST_BUSINESS' => $fixture['business']->id,
            'F1_TEST_BRANCH' => $fixture['branch']->id,
            'F1_TEST_SALE' => $sale->id,
            'F1_TEST_DESTINATION' => $destination->id,
            'F1_TEST_OPERATION' => $operation,
            'CACHE_STORE' => 'array', 'SESSION_DRIVER' => 'array', 'LOG_CHANNEL' => 'stderr',
        ], $input, 20);
        $this->workers[] = $worker;
        try {
            $worker->start();
            $this->awaitMarker($worker, 'READY:');
            preg_match('/READY:(\d+)/', $worker->getOutput(), $connection);
            $this->assertNotSame((int) $connection[1], (int) DB::selectOne('SELECT CONNECTION_ID() AS id')->id);

            DB::beginTransaction();
            $sale->forceFill(['status' => 'completada'])->saveQuietly();
            AccountingService::createFromSale($sale);
            $entry = JournalEntry::withoutBusinessScope()->where('reference', 'Venta #'.$sale->sale_number)->sole();
            $before = [$sale->fresh()->getAttributes(), $item->fresh()->getAttributes(),
                $entry->fresh()->getAttributes(), $entry->lines()->orderBy('id')->get()->toArray()];
            $input->write("start\n");
            $this->awaitMarker($worker, 'BLOCKED');
            DB::commit();
            $input->write("retry\n");
            $input->close();
            $this->assertSame(0, $worker->wait(), $worker->getErrorOutput().$worker->getOutput());
            $this->assertStringContainsString('REJECTED', $worker->getOutput());
            $this->assertSame($before, [$sale->fresh()->getAttributes(), $item->fresh()->getAttributes(),
                $entry->fresh()->getAttributes(), $entry->lines()->orderBy('id')->get()->toArray()]);
            $this->assertSame(1, $sale->items()->count());
            $this->assertSame(1, $destination->items()->count());
            $this->assertSame(20.0, (float) $fixture['product']->fresh()->stock);
        } finally {
            while (DB::transactionLevel() > 0) {
                DB::rollBack();
            }
            $input->close();
            $worker->stop(1);
        }
    }

    public static function financialCompetitors(): array
    {
        return array_map(fn ($operation) => [$operation], [
            'sale-save', 'sale-quiet', 'bulk-sale', 'base-sale',
            'item-save', 'item-delete', 'item-create', 'item-transfer',
        ]);
    }

    public function test_failed_construction_rolls_back_header_lines_event_and_releases_its_permission(): void
    {
        $fixture = $this->saleContext();
        $before = [Sale::withoutBusinessScope()->count(), SaleItem::withoutBusinessScope()->count(),
            AccountingOutbox::withoutBusinessScope()->count(), (float) $fixture['product']->fresh()->stock];
        $reject = true;
        SaleItem::saved(function (SaleItem $item) use (&$reject): void {
            if ($reject) {
                throw new \RuntimeException('F1 construction failure');
            }
        });
        try {
            $this->createSale($fixture);
            $this->fail('Line failure must abort the whole sale.');
        } catch (\RuntimeException $exception) {
            $this->assertSame('F1 construction failure', $exception->getMessage());
        }
        $this->assertSame($before, [Sale::withoutBusinessScope()->count(), SaleItem::withoutBusinessScope()->count(),
            AccountingOutbox::withoutBusinessScope()->count(), (float) $fixture['product']->fresh()->stock]);
        $this->assertSame(0, $this->queue->size($this->salesQueue));
        $reject = false;
        $sale = $this->createSale($fixture);
        try {
            $sale->items()->create(['product_id' => $fixture['product']->id, 'quantity' => 1, 'unit_price' => 50]);
            $this->fail('Successful construction must also release its permission.');
        } catch (ValidationException) {
            $this->assertSame(1, $sale->items()->count());
        }
        $this->runWorker();
        $this->assertPostedOnce($sale);
    }

    public function test_rolled_back_sale_never_leaves_an_accounting_job(): void
    {
        $fixture = $this->saleContext();
        DB::beginTransaction();
        $sale = $this->createSale($fixture);
        DB::rollBack();

        $this->assertFalse(Sale::withoutBusinessScope()->whereKey($sale->id)->exists());
        $this->assertSame(0, AccountingOutbox::withoutBusinessScope()->count());
        $this->assertSame(20.0, (float) $fixture['product']->fresh()->stock);
        $this->assertSame(0, $this->queue->size($this->salesQueue));
        $this->runWorker();
        $this->assertSame(0, JournalEntry::withoutBusinessScope()->count());
    }

    public function test_inline_accounting_remains_inline_when_async_is_disabled(): void
    {
        config(['scale.post_sale_queue' => false]);
        $sale = $this->createSale($this->saleContext());

        $this->assertPostedOnce($sale);
        $this->assertSame(0, $this->queue->size($this->salesQueue));
    }

    public function test_repeated_delivery_of_the_same_sale_does_not_duplicate_entries(): void
    {
        $sale = $this->createSale($this->saleContext());
        $this->runWorker();
        dispatch(new PostSaleAccountingJob((string) $sale->business_id, (string) $sale->id));
        $this->runWorker();

        $this->assertPostedOnce($sale);
        $this->assertSame(0, $this->queue->size($this->salesQueue));
    }

    public function test_failure_rolls_back_partial_lines_and_retry_posts_only_one_entry(): void
    {
        $sale = $this->createSale($this->saleContext());
        $account = Account::withoutBusinessScope()->where('code', '5101')->sole();
        $account->forceFill(['is_system' => false])->saveQuietly();

        $this->runWorker();

        $this->assertSame(0, JournalEntry::withoutBusinessScope()->count());
        $this->assertSame(0, JournalEntryLine::query()->count());
        $this->assertSame(1, $this->queue->delayedSize($this->salesQueue));
        $this->assertSame(0, $this->queue->pendingSize($this->salesQueue));
        $this->assertSame(0, DB::table('failed_jobs')->count());
        $account->forceFill(['is_system' => true])->saveQuietly();
        $this->travel(16)->seconds();
        $this->runWorker();

        $this->assertPostedOnce($sale);
        $this->assertSame(0, $this->queue->size($this->salesQueue));
    }

    public function test_exhausted_job_is_recorded_and_can_be_retried_by_uuid_safely(): void
    {
        $sale = $this->createSale($this->saleContext());
        $account = Account::withoutBusinessScope()->where('code', '5101')->sole();
        $account->forceFill(['is_system' => false])->saveQuietly();

        for ($attempt = 1; $attempt <= 3; $attempt++) {
            $this->runWorker();
            $this->travel(16)->seconds();
        }

        $failed = DB::table('failed_jobs')->sole();
        $this->assertSame('redis', $failed->connection);
        $this->assertSame($this->salesQueue, $failed->queue);
        $this->assertStringContainsString('costo o inventario', $failed->exception);
        $this->assertSame(0, JournalEntry::withoutBusinessScope()->count());
        $this->assertSame(0, JournalEntryLine::query()->count());
        $this->assertSame(0, $this->queue->size($this->salesQueue));

        $account->forceFill(['is_system' => true])->saveQuietly();
        $this->artisan('queue:retry', ['id' => [$failed->uuid]])->assertExitCode(0);
        $this->runWorker();
        $this->assertPostedOnce($sale);
        $this->assertSame(0, DB::table('failed_jobs')->count());

        $this->queue->pushRaw($failed->payload, $this->salesQueue);
        $this->runWorker();
        $this->assertPostedOnce($sale);
        $this->assertSame(0, $this->queue->size($this->salesQueue));
    }

    public function test_one_worker_processes_identical_references_in_different_businesses_without_crossing_accounts(): void
    {
        $a = $this->createSale($this->saleContext());
        $b = $this->createSale($this->saleContext());
        $this->assertSame($a->sale_number, $b->sale_number);

        $this->runWorker('drain');

        $this->assertPostedOnce($a);
        $this->assertPostedOnce($b);
        $this->assertSame(2, JournalEntry::withoutBusinessScope()->count());
        $this->assertSame(0, $this->queue->size($this->salesQueue));
    }

    public function test_a_job_cannot_post_a_sale_belonging_to_another_business(): void
    {
        $sale = $this->createSale($this->saleContext());
        $this->runWorker();
        $other = $this->saleContext();
        dispatch(new PostSaleAccountingJob((string) $other['business']->id, (string) $sale->id));
        $this->runWorker();

        $this->assertPostedOnce($sale);
        $this->assertSame(1, JournalEntry::withoutBusinessScope()->count());
        $this->assertSame(0, $this->queue->size($this->salesQueue));
    }

    public function test_a_sale_cancelled_before_processing_is_not_posted(): void
    {
        $sale = $this->createSale($this->saleContext());
        $sale->forceFill(['status' => 'anulada'])->saveQuietly();
        $this->runWorker();

        $this->assertSame(0, JournalEntry::withoutBusinessScope()->count());
        $this->assertSame(0, $this->queue->size($this->salesQueue));
    }

    public function test_worker_death_after_commit_before_acknowledgement_does_not_duplicate_accounting(): void
    {
        $sale = $this->createSale($this->saleContext());
        $input = new InputStream;
        $worker = $this->startWorker('pause-after-commit', $input);
        try {
            $this->awaitMarker($worker, 'ACCOUNTING_COMMITTED');
            $this->assertPostedOnce($sale);
            $this->assertSame(1, $this->queue->reservedSize($this->salesQueue));
            $worker->stop(1);
            $this->assertFalse($worker->isRunning());
            $this->travel(181)->seconds();
            $this->runWorker();

            $this->assertPostedOnce($sale);
            $this->assertSame(0, $this->queue->size($this->salesQueue));
        } finally {
            $worker->stop(1);
            $input->close();
        }
    }

    public function test_competing_deliveries_lock_the_sale_and_retry_without_duplicate_entries(): void
    {
        $sale = $this->createSale($this->saleContext());
        dispatch(new PostSaleAccountingJob((string) $sale->business_id, (string) $sale->id));
        $input = new InputStream;
        $worker = $this->startWorker('pause-before-commit', $input);
        try {
            $this->awaitMarker($worker, 'ACCOUNTING_UNCOMMITTED');
            $competitor = $this->runWorker();
            $this->assertStringContainsString('Lock wait timeout exceeded', $competitor->getErrorOutput());
            $this->assertSame(1, $this->queue->delayedSize($this->salesQueue));
            $this->assertSame(0, JournalEntry::withoutBusinessScope()->count());
            $input->write("continue\n");
            $worker->wait();
            $this->assertTrue($worker->isSuccessful(), $worker->getErrorOutput().$worker->getOutput());
            $this->travel(16)->seconds();
            $this->runWorker();

            $this->assertPostedOnce($sale);
            $this->assertSame(0, $this->queue->size($this->salesQueue));
        } finally {
            $worker->stop(1);
            $input->close();
        }
    }

    public function test_a_failed_event_insert_rolls_back_the_entire_sale(): void
    {
        $fixture = $this->saleContext();
        AccountingOutbox::creating(function (): void {
            throw new \RuntimeException('outbox insert failure');
        });

        try {
            $this->createSale($fixture);
            $this->fail('La venta no debe confirmarse sin evento.');
        } catch (\RuntimeException $exception) {
            $this->assertSame('outbox insert failure', $exception->getMessage());
        }

        $this->assertSame(0, Sale::withoutBusinessScope()->count());
        $this->assertSame(0, AccountingOutbox::withoutBusinessScope()->count());
        $this->assertSame(0, InventoryMovement::withoutBusinessScope()->count());
        $this->assertSame(20.0, (float) $fixture['product']->fresh()->stock);
        $this->assertSame(0, $this->queue->size($this->salesQueue));
    }

    public function test_redis_failure_keeps_the_sale_and_a_recoverable_event(): void
    {
        $fixture = $this->saleContext();
        $manager = Queue::getFacadeRoot();
        $broken = \Mockery::mock(\Illuminate\Contracts\Queue\Queue::class);
        $broken->shouldReceive('push')->once()->andThrow(new \RuntimeException('redis unavailable'));
        Queue::partialMock()->shouldReceive('connection')->andReturn($broken);
        try {
            $sale = $this->createSale($fixture);
        } finally {
            Queue::swap($manager);
        }

        $event = AccountingOutbox::withoutBusinessScope()->sole();
        $this->assertSame('pending', $event->status);
        $this->assertSame('redis unavailable', $event->last_error);
        $this->assertSame(1, $event->publish_attempts);
        $this->assertTrue($sale->fresh()->exists);
        $this->assertSame(19.0, (float) $fixture['product']->fresh()->stock);
        $this->assertSame(0, $this->queue->size($this->salesQueue));

        $this->travel(31)->seconds();
        $this->assertSame(1, app(AccountingOutboxService::class)->dispatchDue()['published']);
        $this->runWorker();
        $this->assertPostedOnce($sale);
    }

    public function test_lost_published_job_is_recovered_after_the_sql_lease(): void
    {
        $sale = $this->createSale($this->saleContext());
        $outbox = app(AccountingOutboxService::class);
        $this->queue->clear($this->salesQueue);

        $this->assertSame(0, $outbox->dispatchDue()['selected']);
        $this->travel(181)->seconds();
        $this->assertSame(1, $outbox->dispatchDue()['published']);
        $this->runWorker();
        $this->assertPostedOnce($sale);
        $this->assertSame('processed', AccountingOutbox::withoutBusinessScope()->sole()->status);
    }

    public function test_an_expired_publisher_lease_is_reclaimed_without_a_redis_job(): void
    {
        $sale = $this->createSale($this->saleContext());
        $this->queue->clear($this->salesQueue);
        AccountingOutbox::withoutBusinessScope()->sole()->forceFill([
            'status' => 'publishing',
        ])->save();
        $this->travel(181)->seconds();
        $this->assertSame(1, app(AccountingOutboxService::class)->dispatchDue()['published']);
        $this->runWorker();
        $this->assertPostedOnce($sale);
    }

    public function test_recovery_does_not_require_a_failed_jobs_record(): void
    {
        config(['queue.failed.driver' => 'null']);
        $sale = $this->createSale($this->saleContext());
        $account = Account::withoutBusinessScope()->where('code', '5101')->sole();
        $account->forceFill(['is_system' => false])->saveQuietly();
        for ($i = 0; $i < 3; $i++) {
            $this->runWorker();
            $this->travel(16)->seconds();
        }
        $this->assertSame(0, DB::table('failed_jobs')->count());
        $this->assertSame(0, $this->queue->size($this->salesQueue));
        $account->forceFill(['is_system' => true])->saveQuietly();
        $this->travel(31)->seconds();
        $this->assertSame(1, app(AccountingOutboxService::class)->dispatchDue()['published']);
        $this->runWorker();
        $this->assertPostedOnce($sale);
    }

    public function test_old_events_exceeding_five_hundred_are_recovered_in_batches(): void
    {
        $fixture = $this->saleContext();
        $template = $this->createSale($fixture);
        $this->runWorker();
        $old = now()->subDays(10);
        $first = $last = null;
        DB::transaction(function () use ($template, $old, &$first, &$last): void {
            for ($i = 0; $i < 501; $i++) {
                $sale = Sale::withoutEvents(function () use ($template, $old, $i): Sale {
                    $copy = $template->replicate()->forceFill([
                        'sale_number' => 'OLD-'.$i, 'number' => 'OLD-'.$i,
                        'sold_at' => $old, 'sale_date' => $old,
                    ]);

                    return app(SaleFinancialIntegrity::class)->construct($copy, function (Sale $copy) use ($template): Sale {
                        foreach ($template->items as $item) {
                            $item->replicate()->forceFill(['sale_id' => $copy->id])->save();
                        }

                        return $copy;
                    });
                });
                app(AccountingOutboxService::class)->register($sale)->forceFill([
                    'created_at' => $old, 'available_at' => $old,
                ])->save();
                $first ??= $sale;
                $last = $sale;
            }
        });

        $this->artisan('accounting:outbox-dispatch', ['--batch' => 100, '--batches' => 6])
            ->expectsOutput('Revisados: 501. Publicados: 501.')->assertExitCode(0);
        $this->runWorker('drain');

        $this->assertSame(502, AccountingOutbox::withoutBusinessScope()->where('status', 'processed')->count());
        $this->assertSame(502, JournalEntry::withoutBusinessScope()->count());
        $this->assertSame(502, AccountingOutbox::withoutBusinessScope()->distinct()->count('journal_entry_id'));
        $this->assertPostedOnce($first);
        $this->assertPostedOnce($last);
        $this->assertSame(19.0, (float) $fixture['product']->fresh()->stock);
    }

    public function test_processing_budget_keeps_a_failed_event_for_an_explicit_tenant_retry(): void
    {
        config(['scale.accounting_outbox.max_processing_attempts' => 2]);
        $sale = $this->createSale($this->saleContext());
        $account = Account::withoutBusinessScope()->where('code', '5101')->sole();
        $account->forceFill(['is_system' => false])->saveQuietly();
        $this->runWorker();
        $this->travel(16)->seconds();
        $this->runWorker();

        $event = AccountingOutbox::withoutBusinessScope()->sole();
        $this->assertSame('failed', $event->status);
        $this->assertSame(2, $event->processing_attempts);
        $this->assertStringContainsString('costo o inventario', $event->last_error);
        $this->assertSame(0, JournalEntryLine::query()->count());
        $this->assertFalse(app(AccountingOutboxService::class)->retryFailed(
            (string) $event->id, (string) Str::uuid(),
        ));
        $this->queue->clear($this->salesQueue);
        $account->forceFill(['is_system' => true])->saveQuietly();
        $this->artisan('accounting:outbox-retry', [
            'event' => $event->id, '--business' => $sale->business_id,
        ])->assertExitCode(0);
        $this->assertSame(1, $event->fresh()->manual_retries);
        app(AccountingOutboxService::class)->dispatchDue();
        $this->runWorker();
        $this->assertPostedOnce($sale);
    }

    public function test_a_processed_event_does_not_depend_on_the_editable_reference(): void
    {
        $sale = $this->createSale($this->saleContext());
        $this->runWorker();
        $entry = JournalEntry::withoutBusinessScope()->sole();
        $entry->forceFill(['reference' => 'Referencia corregida'])->saveQuietly();
        dispatch(new PostSaleAccountingJob((string) $sale->business_id, (string) $sale->id));
        $this->runWorker();

        $this->assertSame(1, JournalEntry::withoutBusinessScope()->count());
        $this->assertSame((string) $entry->id, (string) AccountingOutbox::withoutBusinessScope()->sole()->journal_entry_id);
    }

    public function test_publishers_share_a_lease_and_cannot_overwrite_a_completed_consumer(): void
    {
        $sale = $this->createSale($this->saleContext());
        $this->queue->clear($this->salesQueue);
        $this->travel(181)->seconds();
        $input = new InputStream;
        $publisher = $this->startWorker('pause-after-publish', $input);
        try {
            $this->awaitMarker($publisher, 'OUTBOX_PUBLISHED');
            $competitor = $this->runWorker('publish-once');
            $this->assertStringContainsString('PUBLISHED:0', $competitor->getOutput());
            $this->assertSame(1, $this->queue->pendingSize($this->salesQueue));
            $this->runWorker();
            $input->write("continue\n");
            $publisher->wait();
            $this->assertTrue($publisher->isSuccessful(), $publisher->getErrorOutput().$publisher->getOutput());
            $this->assertPostedOnce($sale);
        } finally {
            $publisher->stop(1);
            $input->close();
        }
    }

    public function test_reconfirming_an_unposted_sale_resumes_its_skipped_event(): void
    {
        $sale = $this->createSale($this->saleContext());
        $sale->forceFill(['status' => 'anulada'])->saveQuietly();
        $this->runWorker();
        $this->assertSame('skipped', AccountingOutbox::withoutBusinessScope()->sole()->status);

        $sale->update(['status' => 'completada']);
        $this->runWorker();
        $this->assertPostedOnce($sale);
        $this->assertSame(1, AccountingOutbox::withoutBusinessScope()->count());
    }

    public function test_existing_sale_completion_and_its_event_are_atomic(): void
    {
        $fixture = $this->saleContext();
        $template = $this->createSale($fixture);
        $sale = Sale::withoutEvents(function () use ($template): Sale {
            $copy = $template->replicate()->forceFill([
                'sale_number' => 'EXISTING-1', 'number' => 'EXISTING-1', 'status' => 'anulada',
            ]);

            return app(SaleFinancialIntegrity::class)->construct($copy, function (Sale $copy) use ($template): Sale {
                $copy->unsetRelation('items');
                foreach ($template->items as $item) {
                    $item->replicate()->forceFill(['sale_id' => $copy->id])->save();
                }

                return $copy;
            });
        });
        $reject = true;
        AccountingOutbox::creating(function (AccountingOutbox $event) use ($sale, &$reject): void {
            if ($reject && (string) $event->sale_id === (string) $sale->id) {
                throw new \RuntimeException('completion event failure');
            }
        });
        try {
            $sale->update(['status' => 'completada']);
            $this->fail('El UPDATE no debe confirmarse sin evento.');
        } catch (\RuntimeException $exception) {
            $this->assertSame('completion event failure', $exception->getMessage());
        }
        $this->assertSame('anulada', $sale->fresh()->status->value);
        $this->assertSame(1, AccountingOutbox::withoutBusinessScope()->count());
        $this->assertSame(19.0, (float) $fixture['product']->fresh()->stock);

        $reject = false;
        $sale = $sale->fresh();
        $sale->update(['status' => 'completada']);
        $this->runWorker('drain');
        $this->assertPostedOnce($sale);
        $this->assertSame(2, AccountingOutbox::withoutBusinessScope()->count());
    }

    public function test_distinct_sales_serialize_numbering_across_workers(): void
    {
        $fixture = $this->saleContext();
        $a = $this->createSale($fixture);
        $b = $this->createSale($fixture);
        $input = new InputStream;
        $worker = $this->startWorker('pause-before-commit', $input);
        try {
            $this->awaitMarker($worker, 'ACCOUNTING_UNCOMMITTED');
            $competitor = $this->runWorker();
            $this->assertStringContainsString('Lock wait timeout exceeded', $competitor->getErrorOutput());
            $input->write("continue\n");
            $worker->wait();
            $this->assertTrue($worker->isSuccessful(), $worker->getErrorOutput().$worker->getOutput());
            $this->travel(16)->seconds();
            $this->runWorker();
            $this->assertPostedOnce($a);
            $this->assertPostedOnce($b);
            $this->assertSame(2, JournalEntry::withoutBusinessScope()->distinct()->count('entry_number'));
        } finally {
            $worker->stop(1);
            $input->close();
        }
    }

    public function test_publication_budget_retains_a_failed_event_without_silent_loss(): void
    {
        config(['scale.accounting_outbox.max_publish_attempts' => 1]);
        $fixture = $this->saleContext();
        $manager = Queue::getFacadeRoot();
        $broken = \Mockery::mock(\Illuminate\Contracts\Queue\Queue::class);
        $broken->shouldReceive('push')->once()->andThrow(new \RuntimeException('redis unavailable'));
        Queue::partialMock()->shouldReceive('connection')->andReturn($broken);
        try {
            $sale = $this->createSale($fixture);
        } finally {
            Queue::swap($manager);
        }
        $event = AccountingOutbox::withoutBusinessScope()->sole();
        $this->assertSame('failed', $event->status);
        $this->assertSame(1, $event->publish_attempts);
        $this->travel(181)->seconds();
        $this->assertSame(0, app(AccountingOutboxService::class)->dispatchDue()['selected']);
        $this->assertTrue($sale->fresh()->exists);
        $this->assertSame(0, JournalEntry::withoutBusinessScope()->count());
    }

    public function test_numbering_preserves_archived_legacy_numbers_above_five_digits(): void
    {
        $sale = $this->createSale($this->saleContext());
        $prefix = 'ASI-'.now()->year.'-';
        foreach (['99999', '100000'] as $number) {
            $entry = JournalEntry::forceCreate([
                'business_id' => $sale->business_id,
                'entry_number' => $prefix.$number,
                'date' => now()->toDateString(),
                'description' => 'Fixture contable heredado',
                'status' => JournalEntry::STATUS_DRAFT,
                'total_debit' => 0, 'total_credit' => 0,
            ]);
            if ($number === '100000') {
                $entry->forceFill(['deleted_at' => now()])->saveQuietly();
            }
        }
        $this->runWorker();
        $this->assertPostedOnce($sale);
        $this->assertSame($prefix.'100001', JournalEntry::withoutBusinessScope()
            ->where('reference', 'Venta #'.$sale->sale_number)->sole()->entry_number);
    }

    private function saleContext(): array
    {
        $user = User::factory()->create();
        $business = Business::withoutEvents(fn () => Business::create([
            'name' => 'Contabilidad en cola', 'type' => BusinessType::Colmado,
            'slug' => 'queue-'.bin2hex(random_bytes(5)),
            'owner_id' => $user->id, 'is_active' => true,
        ]));
        $user->businesses()->attach($business->id, ['role' => 'owner', 'is_active' => true]);
        $this->actingAs($user);
        app()->instance('currentBusinessId', $business->id);
        app()->instance('currentBusiness', $business);
        $branch = Branch::withoutBusinessScope()->forceCreate([
            'business_id' => $business->id, 'name' => 'Principal', 'is_active' => true,
        ]);
        session([
            AuthContextResolver::SESSION_BUSINESS_ID => $business->id,
            AuthContextResolver::SESSION_BRANCH_ID => $branch->id,
        ]);
        AccountingService::createDefaultAccounts($business);
        $product = Product::withoutBusinessScope()->forceCreate([
            'business_id' => $business->id, 'branch_id' => $branch->id,
            'name' => 'Producto de cola', 'price' => 50, 'cost' => 30, 'stock' => 20,
            'min_stock' => 1, 'is_active' => true, 'track_stock' => true,
            'inventory_behavior' => 'deduct_on_sale',
        ]);

        return compact('user', 'business', 'branch', 'product');
    }

    private function creditCommand(array $fixture): array
    {
        $customer = Customer::forceCreate([
            'business_id' => $fixture['business']->id, 'name' => 'Cliente concurrente F2', 'is_active' => true,
        ]);

        return [
            'idempotency_key' => (string) Str::uuid(), 'business_id' => $fixture['business']->id,
            'user_id' => $fixture['user']->id, 'customer_id' => $customer->id,
            'payment_method' => 'credito',
            'items' => [['product_id' => $fixture['product']->id, 'quantity' => 1, 'unit_price' => 50]],
        ];
    }

    private function startCreditWorker(array $command, array $fixture, string $mode, InputStream $input, ?Sale $source = null): Process
    {
        $worker = new Process([PHP_BINARY, base_path('tests/Fixtures/sale-credit-concurrent-worker.php')], base_path(), [
            'F2_TEST_PREFIX' => $this->prefix, 'F2_TEST_MODE' => $mode,
            'F2_TEST_COMMAND' => json_encode($command, JSON_THROW_ON_ERROR),
            'F2_TEST_SALE' => $source?->id ?? '',
            'F2_TEST_BRANCH' => $fixture['branch']->id, 'F2_TEST_NOW' => (string) now()->timestamp,
            'QUEUE_CONNECTION' => 'redis', 'REDIS_QUEUE' => $this->defaultQueue,
            'SCALE_QUEUE_SALES' => $this->salesQueue, 'SCALE_QUEUE_REPORTS' => $this->reportsQueue,
            'SCALE_POST_SALE_QUEUE' => 'true', 'CACHE_STORE' => 'array',
            'SESSION_DRIVER' => 'array', 'LOG_CHANNEL' => 'stderr',
        ], $input, 30);
        $this->workers[] = $worker;
        $worker->start();

        return $worker;
    }

    private function cancelledFinancialSale(array $fixture, string $id): Sale
    {
        $sale = (new Sale)->forceFill([
            'id' => $id,
            'business_id' => $fixture['business']->id, 'branch_id' => $fixture['branch']->id,
            'sale_number' => 'F1-'.Str::uuid(), 'number' => 'F1-'.Str::uuid(),
            'status' => 'anulada', 'payment_method' => 'efectivo', 'amount_paid' => 59,
            'subtotal' => 50, 'tax' => 9, 'tax_rate' => 18, 'discount' => 0, 'total' => 59,
            'sale_date' => now(), 'sold_at' => now(), 'user_id' => $fixture['user']->id,
            'created_by' => $fixture['user']->id,
        ]);

        return Sale::withoutEvents(fn () => app(SaleFinancialIntegrity::class)->construct($sale, function (Sale $sale) use ($fixture): Sale {
            $item = new SaleItem;
            $item->forceFill([
                'business_id' => $sale->business_id, 'sale_id' => $sale->id,
                'product_id' => $fixture['product']->id, 'product_name' => 'Producto F1',
                'quantity' => 1, 'unit_price' => 50, 'unit_cost' => 30, 'subtotal' => 50, 'line_total' => 50,
            ])->saveQuietly();

            return $sale;
        }));
    }

    private function createSale(array $fixture): Sale
    {
        return app(RecordsSale::class)->create([
            'business_id' => $fixture['business']->id,
            'user_id' => $fixture['user']->id,
            'payment_method' => 'efectivo', 'amount_paid' => 59,
            'items' => [[
                'product_id' => $fixture['product']->id, 'quantity' => 1, 'unit_price' => 50,
            ]],
        ]);
    }

    private function assertPostedOnce(Sale $sale): void
    {
        $entry = JournalEntry::withoutBusinessScope()
            ->where('business_id', $sale->business_id)
            ->where('reference', 'Venta #'.$sale->sale_number)->sole();
        $this->assertTrue($entry->isPosted());
        $this->assertSame(89.0, (float) $entry->total_debit);
        $this->assertSame(89.0, (float) $entry->total_credit);
        $this->assertSame(5, $entry->lines()->count());
        $event = AccountingOutbox::withoutBusinessScope()
            ->where('business_id', $sale->business_id)->where('sale_id', $sale->id)->sole();
        $this->assertSame('processed', $event->status);
        $this->assertSame((string) $entry->id, (string) $event->journal_entry_id);
        $this->assertSame([(string) $sale->business_id], Account::withoutBusinessScope()
            ->whereIn('id', $entry->lines()->pluck('account_id'))
            ->pluck('business_id')->unique()->all());
        if ($sale->isCreditSale()) {
            $account = Account::withoutBusinessScope()->where('business_id', $sale->business_id)->where('code', '1201')->sole();
            $this->assertSame((float) $sale->total, (float) $entry->lines()->where('account_id', $account->id)->sum('debit'));
            $this->assertSame((string) $sale->customer_id,
                (string) app(SaleCreditService::class)->assertReceivable($sale)->customer_id);
        }
    }

    private function startWorker(string $mode, ?InputStream $input = null): Process
    {
        $worker = new Process([PHP_BINARY, base_path('tests/Fixtures/post-sale-accounting-worker.php')],
            base_path(), [
                'QUEUE_TEST_PREFIX' => $this->prefix,
                'QUEUE_TEST_MODE' => $mode,
                'QUEUE_TEST_NOW' => (string) now()->timestamp,
                'QUEUE_CONNECTION' => 'redis',
                'REDIS_QUEUE' => $this->defaultQueue,
                'REDIS_QUEUE_RETRY_AFTER' => '180',
                'SCALE_QUEUE_SALES' => $this->salesQueue,
                'SCALE_QUEUE_REPORTS' => $this->reportsQueue,
                'SCALE_POST_SALE_QUEUE' => 'true',
                'ACCOUNTING_OUTBOX_MAX_PROCESSING_ATTEMPTS' => (string) config('scale.accounting_outbox.max_processing_attempts'),
                'QUEUE_FAILED_DRIVER' => (string) config('queue.failed.driver'),
                'CACHE_STORE' => 'array',
                'SESSION_DRIVER' => 'array',
                'LOG_CHANNEL' => 'stderr',
            ], timeout: $mode === 'drain' ? 180 : 20);
        $worker->setInput($input);
        $this->workers[] = $worker;
        $worker->start();

        return $worker;
    }

    private function runWorker(string $mode = 'once'): Process
    {
        $worker = $this->startWorker($mode);
        $worker->wait();
        $this->assertTrue($worker->isSuccessful(), $worker->getErrorOutput().$worker->getOutput());
        $this->assertSame(1, preg_match('/CONNECTION:(\d+)/', $worker->getOutput(), $matches));
        $this->assertNotSame((int) DB::selectOne('SELECT CONNECTION_ID() AS id')->id, (int) $matches[1]);

        return $worker;
    }

    private function awaitMarker(Process $worker, string $marker): void
    {
        $output = '';
        $this->assertTrue($worker->waitUntil(function (string $type, string $buffer) use (&$output, $marker): bool {
            $output .= $buffer;

            return str_contains($output, $marker);
        }), $worker->getErrorOutput().$worker->getOutput());
    }
}
