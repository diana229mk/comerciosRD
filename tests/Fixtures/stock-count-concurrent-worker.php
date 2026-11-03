<?php

use App\Models\StockCount;
use App\Models\User;
use App\Services\AuthContextResolver;
use App\Services\StockCountService;
use App\Support\TestingDatabaseGuard;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;

require dirname(__DIR__, 2).'/vendor/autoload.php';

try {
    $prefix = getenv('STOCK_COUNT_TEST_PREFIX');
    if (getenv('APP_ENV') !== 'testing' || ! is_string($prefix)
        || preg_match('/^crd_count_test_[a-f0-9]{16}_$/D', $prefix) !== 1) {
        throw new RuntimeException('Este worker requiere el runner aislado y tablas nuevas de pruebas.');
    }
    $app = require dirname(__DIR__, 2).'/bootstrap/app.php';
    $app->make(Kernel::class)->bootstrap();
    TestingDatabaseGuard::assertIsolated($app);
    if (config('database.connections.mysql.host') !== 'test-mysql'
        || config('database.connections.mysql.unix_socket') !== '') {
        throw new RuntimeException('Solo se permite MySQL de pruebas mediante test-mysql.');
    }
    config(['database.connections.mysql.prefix' => $prefix]);
    DB::purge('mysql');
    Auth::setUser(User::findOrFail(getenv('STOCK_COUNT_TEST_USER')));
    session([
        AuthContextResolver::SESSION_BUSINESS_ID => getenv('STOCK_COUNT_TEST_BUSINESS'),
        AuthContextResolver::SESSION_BRANCH_ID => getenv('STOCK_COUNT_TEST_BRANCH'),
    ]);
    app()->instance('currentBusinessId', getenv('STOCK_COUNT_TEST_BUSINESS'));
    $count = StockCount::withoutBusinessScope()->findOrFail(getenv('STOCK_COUNT_TEST_COUNT'));
    $itemId = $count->items()->sole()->id;
    $operation = getenv('STOCK_COUNT_TEST_OPERATION');
    $service = app(StockCountService::class);
    $invoke = match ($operation) {
        'complete' => fn () => $service->complete($count),
        'saveCount' => fn () => $service->saveCount($count, [$itemId => 0]),
        'cancel' => fn () => $service->cancel($count),
        default => throw new RuntimeException('Operación de prueba desconocida.'),
    };
    DB::statement('SET SESSION innodb_lock_wait_timeout = 1');
    fwrite(STDOUT, 'READY:'.DB::selectOne('SELECT CONNECTION_ID() AS id')->id."\n");
    fflush(STDOUT);
    if (trim((string) fgets(STDIN)) !== 'start') {
        throw new RuntimeException('Falta señal de inicio.');
    }
    try {
        $invoke();
        throw new RuntimeException('La operación concurrente no esperó el bloqueo.');
    } catch (QueryException $exception) {
        if ((int) ($exception->errorInfo[1] ?? 0) !== 1205) {
            throw $exception;
        }
    }
    fwrite(STDOUT, "BLOCKED\n");
    fflush(STDOUT);
    if (trim((string) fgets(STDIN)) !== 'retry') {
        throw new RuntimeException('Falta señal de reintento.');
    }
    $expected = match ($operation) {
        'complete' => 'Solo se pueden completar conteos en borrador o en captura.',
        'saveCount' => 'El conteo ya fue cerrado.',
        'cancel' => 'No se puede cancelar un conteo ya aplicado.',
    };
    try {
        $invoke();
        throw new RuntimeException('La operación concurrente aceptó un estado obsoleto.');
    } catch (RuntimeException $exception) {
        if ($exception->getMessage() !== $expected) {
            throw $exception;
        }
    }
    fwrite(STDOUT, "REJECTED\n");
    exit(0);
} catch (Throwable $exception) {
    fwrite(STDERR, $exception::class.': '.$exception->getMessage()."\n");
    exit(1);
}
