<?php

use App\Models\User;
use App\Services\AuthContextResolver;
use App\Services\SaleService;
use App\Support\TestingDatabaseGuard;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Database\QueryException;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

require dirname(__DIR__, 2).'/vendor/autoload.php';

try {
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
    fwrite(STDOUT, 'READY:'.DB::selectOne('SELECT CONNECTION_ID() AS id')->id."\n");
    fflush(STDOUT);
    if (trim((string) fgets(STDIN)) !== 'start') {
        throw new RuntimeException('Falta señal de inicio.');
    }
    if (in_array($mode, ['same', 'conflict', 'unique-source'], true)) {
        DB::beginTransaction();
        try {
            $operation();
            throw new RuntimeException('El checkout no esperó el bloqueo MySQL.');
        } catch (QueryException $exception) {
            if ((int) ($exception->errorInfo[1] ?? 0) !== 1205) {
                throw $exception;
            }
        } finally {
            while (DB::transactionLevel() > 0) {
                DB::rollBack();
            }
        }
        fwrite(STDOUT, "BLOCKED\n");
        fflush(STDOUT);
        if (trim((string) fgets(STDIN)) !== 'retry') {
            throw new RuntimeException('Falta señal de reintento.');
        }
    }
    try {
        $sale = $operation();
        if (in_array($mode, ['conflict', 'unique-source'], true)) {
            throw new RuntimeException('Se reutilizó una clave incompatible.');
        }
        fwrite(STDOUT, ($mode === 'after-commit' ? 'CREDIT_COMMITTED:' : 'SALE:').$sale->id."\n");
        fflush(STDOUT);
        if ($mode === 'after-commit') {
            fgets(STDIN);
        }
    } catch (ValidationException $exception) {
        if ($mode !== 'conflict') {
            throw $exception;
        }
        fwrite(STDOUT, "CONFLICT\n");
    } catch (QueryException $exception) {
        if ($mode !== 'unique-source' || (int) ($exception->errorInfo[1] ?? 0) !== 1062
            || ! str_contains($exception->getMessage(), 'receivables_business_sale_unique')) {
            throw $exception;
        }
        fwrite(STDOUT, "UNIQUE_REJECTED\n");
    }
} catch (Throwable $exception) {
    fwrite(STDERR, $exception::class.': '.$exception->getMessage()."\n");
    exit(1);
}
