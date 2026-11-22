<?php

/*
 * DATA-02: worker de concurrencia CxC. Abre su propia conexión MySQL contra las copias
 * prefijadas creadas por ReceivablePaymentConcurrencyTest y ejecuta una sola operación.
 *
 * Protocolo (stdout): READY:<connection_id>  ->  espera "start" en stdin  ->  RESULT:<json>
 */

use App\Models\CashSession;
use App\Models\Receivable;
use App\Models\ReceivablePayment;
use App\Models\User;
use App\Services\AuthContextResolver;
use App\Services\CashSessionService;
use App\Services\ReceivablePaymentService;
use App\Support\TenantContext;
use App\Support\TestingDatabaseGuard;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Database\QueryException;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;

require __DIR__.'/_guard.php';

try {
    $prefix = getenv('DATA02_CC_PREFIX');
    if (getenv('APP_ENV') !== 'testing' || getenv('DATA02_CONCURRENCY_INTEGRATION') !== '1'
        || ! is_string($prefix) || preg_match('/^crd_data02_cc_[a-f0-9]{16}_$/D', $prefix) !== 1) {
        throw new RuntimeException('Este worker solo admite copias DATA-02 aisladas y prefijadas.');
    }
    $command = json_decode((string) getenv('DATA02_CC_COMMAND'), true, flags: JSON_THROW_ON_ERROR);
    if (! in_array($command['op'] ?? null, ['collect', 'reverse', 'close'], true)) {
        throw new RuntimeException('Operación de worker no admitida.');
    }

    $app = require dirname(__DIR__, 2).'/bootstrap/app.php';
    $app->make(Kernel::class)->bootstrap();
    TestingDatabaseGuard::assertIsolated($app);
    if (config('database.connections.mysql.host') !== 'test-mysql'
        || config('database.connections.mysql.unix_socket') !== ''
        || config('database.connections.mysql.prefix') !== '') {
        throw new RuntimeException('Endpoint fuera del contrato aislado.');
    }
    config(['database.connections.mysql.prefix' => $prefix]);
    DB::purge('mysql');

    $now = (string) getenv('DATA02_CC_NOW');
    if (! ctype_digit($now)) {
        throw new RuntimeException('Falta reloj de prueba.');
    }
    Carbon::setTestNow(Carbon::createFromTimestamp((int) $now));

    $businessId = (string) $command['business_id'];
    Auth::setUser(User::findOrFail($command['user_id']));
    app()->instance('currentBusinessId', $businessId);
    session([AuthContextResolver::SESSION_BUSINESS_ID => $businessId]);

    DB::statement('SET SESSION innodb_lock_wait_timeout = '.(int) ($command['lock_wait_timeout'] ?? 20));
    $connectionId = (int) DB::selectOne('SELECT CONNECTION_ID() AS id')->id;
    fwrite(STDOUT, 'READY:'.$connectionId."\n");
    fflush(STDOUT);
    if (trim((string) fgets(STDIN)) !== 'start') {
        throw new RuntimeException('Falta señal de inicio.');
    }

    $started = hrtime(true);
    try {
        $value = TenantContext::run($businessId, function () use ($command, $businessId): array {
            return match ($command['op']) {
                'collect' => (function () use ($command): array {
                    $receivable = Receivable::withoutBusinessScope()->findOrFail($command['receivable_id']);
                    $payment = app(ReceivablePaymentService::class)->collect(
                        $receivable,
                        (float) $command['amount'],
                        (string) $command['method'],
                        requestKey: $command['request_key'] ?? null,
                    );

                    return ['payment_id' => (string) $payment->id];
                })(),
                'reverse' => (function () use ($command): array {
                    $payment = ReceivablePayment::withoutBusinessScope()->findOrFail($command['payment_id']);
                    $reversal = app(ReceivablePaymentService::class)->reverse(
                        $payment,
                        (string) $command['reason'],
                        $command['request_key'] ?? null,
                    );

                    return ['reversal_id' => (string) $reversal->id];
                })(),
                'close' => (function () use ($command, $businessId): array {
                    $session = CashSession::withoutBusinessScope()
                        ->where('business_id', $businessId)
                        ->findOrFail($command['cash_session_id']);
                    $closed = app(CashSessionService::class)->close($session, (float) $command['closing_amount']);

                    return ['expected_amount' => (string) $closed->expected_amount];
                })(),
            };
        });
        $result = ['status' => 'ok'] + $value;
    } catch (Throwable $exception) {
        while (DB::transactionLevel() > 0) {
            DB::rollBack();
        }
        $result = [
            'status' => 'error',
            'exception' => $exception::class,
            'message' => $exception->getMessage(),
            'mysql_code' => (function (?Throwable $e): ?int {
                // Laravel envuelve 1205/1213 en DeadlockException dentro de transacciones anidadas.
                for (; $e !== null; $e = $e->getPrevious()) {
                    if ($e instanceof QueryException || $e instanceof PDOException) {
                        $code = $e->errorInfo[1] ?? null;
                        if (is_int($code)) {
                            return $code;
                        }
                    }
                }

                return null;
            })($exception),
        ];
    }
    $result['elapsed_ms'] = round((hrtime(true) - $started) / 1_000_000, 3);
    fwrite(STDOUT, 'RESULT:'.json_encode($result, JSON_THROW_ON_ERROR)."\n");
    fflush(STDOUT);
} catch (Throwable $exception) {
    fwrite(STDERR, 'DATA02_CC_WORKER_BOOT_FAILURE '.$exception::class.': '.$exception->getMessage().PHP_EOL);
    exit(1);
}
