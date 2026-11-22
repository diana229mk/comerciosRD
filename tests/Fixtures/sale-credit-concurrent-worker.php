<?php

use App\Models\User;
use App\Services\AuthContextResolver;
use App\Services\SaleService;
use App\Support\TestingDatabaseGuard;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Database\Events\TransactionBeginning;
use Illuminate\Database\Events\TransactionCommitted;
use Illuminate\Database\Events\TransactionRolledBack;
use Illuminate\Database\QueryException;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

require dirname(__DIR__, 2).'/vendor/autoload.php';

try {
    require __DIR__.'/_guard.php';
    $prefix = getenv('F2_TEST_PREFIX');
    $mode = getenv('F2_TEST_MODE');
    if (getenv('APP_ENV') !== 'testing' || getenv('ACCOUNTING_QUEUE_INTEGRATION') !== '1'
        || ! is_string($prefix) || preg_match('/^crd_accounting_test_[a-f0-9]{16}_$/D', $prefix) !== 1
        || ! in_array($mode, ['same', 'conflict', 'unique-source', 'before-commit', 'after-commit'], true)) {
        throw new RuntimeException('Este worker solo admite copias aisladas y modos F2 explícitos.');
    }
    $app = require dirname(__DIR__, 2).'/bootstrap/app.php';
    $app->make(Kernel::class)->bootstrap();
    TestingDatabaseGuard::assertIsolated($app);
    if (config('database.connections.mysql.host') !== 'test-mysql'
        || config('database.connections.mysql.unix_socket') !== ''
        || config('database.redis.default.host') !== 'test-redis'
        || filled(config('database.redis.default.url'))
        || (string) config('database.redis.default.database') !== '15') {
        throw new RuntimeException('Endpoint fuera del contrato aislado.');
    }
    config(['database.connections.mysql.prefix' => $prefix, 'database.redis.options.prefix' => $prefix]);
    DB::purge('mysql');
    $app['redis']->purge('default');
    $traceStart = hrtime(true);
    $traceConnectionId = null;
    $trace = static function (string $event, array $extra = []) use (&$traceConnectionId, $traceStart): void {
        $now = hrtime(true);
        fwrite(STDERR, 'F2_LOCK_TRACE '.json_encode([
            'event' => $event,
            'pid' => getmypid(),
            'connection_id' => $traceConnectionId,
            'transaction_level' => DB::transactionLevel(),
            'monotonic_ns' => $now,
            'elapsed_ms' => round(($now - $traceStart) / 1_000_000, 3),
        ] + $extra, JSON_THROW_ON_ERROR).PHP_EOL);
    };
    $traceSql = static function (string $event, string $sql, array $extra = []) use ($trace): void {
        $normalized = strtolower($sql);
        $table = str_contains($normalized, 'sale_checkout_requests') ? 'sale_checkout_requests'
            : (str_contains($normalized, 'receivables') ? 'receivables' : null);
        if ($table === null) {
            return;
        }

        $verb = strtoupper(strtok(ltrim($sql), " \t\n\r") ?: 'unknown');
        $kind = str_contains($normalized, 'for update') ? 'lock_for_update'
            : (in_array($verb, ['INSERT', 'UPDATE', 'DELETE'], true) ? 'write' : 'read');
        $trace($event, ['table' => $table, 'verb' => $verb, 'kind' => $kind] + $extra);
    };
    DB::connection()->beforeExecuting(function (string $sql) use ($traceSql): void {
        $traceSql('sql_begin', $sql);
    });
    DB::listen(function (QueryExecuted $query) use ($traceSql): void {
        $traceSql('sql_complete', $query->sql, ['duration_ms' => $query->time]);
    });
    foreach ([
        TransactionBeginning::class => 'transaction_begin',
        TransactionCommitted::class => 'transaction_commit',
        TransactionRolledBack::class => 'transaction_rollback',
    ] as $eventClass => $eventName) {
        $app['events']->listen($eventClass, function (object $event) use ($eventName, $trace): void {
            if ($event->connectionName === 'mysql') {
                $trace($eventName);
            }
        });
    }
    $command = json_decode((string) getenv('F2_TEST_COMMAND'), true, flags: JSON_THROW_ON_ERROR);
    Auth::setUser(User::findOrFail($command['user_id']));
    app()->instance('currentBusinessId', $command['business_id']);
    session([
        AuthContextResolver::SESSION_BUSINESS_ID => $command['business_id'],
        AuthContextResolver::SESSION_BRANCH_ID => getenv('F2_TEST_BRANCH'),
    ]);
    $now = (string) getenv('F2_TEST_NOW');
    if (! ctype_digit($now)) {
        throw new RuntimeException('Falta reloj de prueba.');
    }
    Carbon::setTestNow(Carbon::createFromTimestamp((int) $now));
    if ($mode === 'conflict') {
        $command['items'][0]['quantity'] = 2;
    }
    if ($mode === 'before-commit') {
        DB::listen(function (QueryExecuted $query): void {
            if (str_starts_with(strtolower(ltrim($query->sql)), 'insert') && str_contains($query->sql, 'sales_daily')) {
                fwrite(STDOUT, "CREDIT_UNCOMMITTED\n");
                fflush(STDOUT);
                fgets(STDIN);
            }
        });
    }
    $operation = function () use ($mode, $command) {
        if ($mode !== 'unique-source') {
            return app(SaleService::class)->create($command);
        }

        return DB::table('receivables')->insert([
            'id' => (string) Str::uuid(), 'business_id' => $command['business_id'],
            'sale_id' => getenv('F2_TEST_SALE'), 'customer_id' => $command['customer_id'],
            'original_amount' => '59.00', 'amount' => '59.00', 'paid_amount' => 0, 'balance' => '59.00',
            'description' => 'Competidor SQL F2', 'due_date' => today()->addDays(7)->toDateString(),
        ]);
    };
    DB::statement('SET SESSION innodb_lock_wait_timeout = 1');
    $traceConnectionId = (int) DB::selectOne('SELECT CONNECTION_ID() AS id')->id;
    $trace('worker_ready');
    fwrite(STDOUT, 'READY:'.$traceConnectionId."\n");
    fflush(STDOUT);
    $signal = trim((string) fgets(STDIN));
    $trace('start_received', ['signal' => $signal === 'start' ? 'start' : 'unexpected']);
    if ($signal !== 'start') {
        throw new RuntimeException('Falta señal de inicio.');
    }
    if (in_array($mode, ['same', 'conflict', 'unique-source'], true)) {
        $trace('wait_transaction_begin');
        DB::beginTransaction();
        $trace('wait_transaction_active');
        try {
            $trace('first_operation_begin');
            $operation();
            $trace('first_operation_returned_unexpectedly');
            throw new RuntimeException('El checkout no esperó el bloqueo MySQL.');
        } catch (Throwable $exception) {
            $queryException = null;
            for ($current = $exception; $current !== null; $current = $current->getPrevious()) {
                if ($current instanceof QueryException) {
                    $queryException = $current;
                    break;
                }
            }
            $mysqlCode = (int) ($queryException?->errorInfo[1] ?? 0);
            $trace('first_operation_exception', [
                'exception' => $exception::class,
                'query_exception' => $queryException ? $queryException::class : null,
                'sqlstate' => $queryException?->errorInfo[0] ?? null,
                'mysql_code' => $mysqlCode,
            ]);
            if ($mysqlCode !== 1205) {
                throw $exception;
            }
        } finally {
            while (DB::transactionLevel() > 0) {
                $trace('worker_rollback_begin');
                DB::rollBack();
                $trace('worker_rollback_complete');
            }
        }
        $trace('blocked_emitted');
        fwrite(STDOUT, "BLOCKED\n");
        fflush(STDOUT);
        $signal = trim((string) fgets(STDIN));
        $trace('retry_received', ['signal' => $signal === 'retry' ? 'retry' : 'unexpected']);
        if ($signal !== 'retry') {
            throw new RuntimeException('Falta señal de reintento.');
        }
    }
    try {
        $trace('retry_operation_begin');
        $sale = $operation();
        $trace('retry_operation_returned');
        if (in_array($mode, ['conflict', 'unique-source'], true)) {
            throw new RuntimeException('Se reutilizó una clave incompatible.');
        }
        fwrite(STDOUT, ($mode === 'after-commit' ? 'CREDIT_COMMITTED:' : 'SALE:').$sale->id."\n");
        fflush(STDOUT);
        $trace('sale_marker_emitted');
        if ($mode === 'after-commit') {
            fgets(STDIN);
        }
    } catch (ValidationException $exception) {
        if ($mode !== 'conflict') {
            throw $exception;
        }
        $trace('payload_conflict');
        fwrite(STDOUT, "CONFLICT\n");
    } catch (QueryException $exception) {
        $trace('retry_query_exception', [
            'sqlstate' => $exception->errorInfo[0] ?? null,
            'mysql_code' => (int) ($exception->errorInfo[1] ?? 0),
        ]);
        if ($mode !== 'unique-source' || (int) ($exception->errorInfo[1] ?? 0) !== 1062
            || ! str_contains($exception->getMessage(), 'receivables_business_sale_unique')) {
            throw $exception;
        }
        $trace('unique_source_rejected');
        fwrite(STDOUT, "UNIQUE_REJECTED\n");
    }
    $trace('worker_finished', ['result' => 'success']);
} catch (Throwable $exception) {
    if (isset($trace)) {
        $exceptionChain = [];
        for ($current = $exception; $current !== null && count($exceptionChain) < 8; $current = $current->getPrevious()) {
            $errorInfo = $current instanceof QueryException ? $current->errorInfo : null;
            $exceptionChain[] = [
                'exception' => $current::class,
                'code' => $current->getCode(),
                'sqlstate' => $errorInfo[0] ?? null,
                'mysql_code' => isset($errorInfo[1]) ? (int) $errorInfo[1] : null,
            ];
        }
        $trace('worker_exception', [
            'exception_chain' => $exceptionChain,
        ]);
        $trace('worker_finished', ['result' => 'error']);
    } else {
        fwrite(STDERR, 'F2_LOCK_TRACE '.json_encode([
            'event' => 'worker_bootstrap_exception',
            'pid' => getmypid(),
            'exception' => $exception::class,
            'monotonic_ns' => hrtime(true),
        ], JSON_THROW_ON_ERROR).PHP_EOL);
    }
    exit(1);
}
