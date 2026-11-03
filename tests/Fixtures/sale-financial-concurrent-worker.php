<?php

use App\Models\Sale;
use App\Models\User;
use App\Services\AuthContextResolver;
use App\Support\TestingDatabaseGuard;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

require dirname(__DIR__, 2).'/vendor/autoload.php';

try {
    $prefix = getenv('F1_TEST_PREFIX');
    if (getenv('APP_ENV') !== 'testing' || getenv('ACCOUNTING_QUEUE_INTEGRATION') !== '1'
        || ! is_string($prefix) || preg_match('/^crd_accounting_test_[a-f0-9]{16}_$/D', $prefix) !== 1) {
        throw new RuntimeException('Este worker solo admite copias aisladas de pruebas F1.');
    }
    $app = require dirname(__DIR__, 2).'/bootstrap/app.php';
    $app->make(Kernel::class)->bootstrap();
    TestingDatabaseGuard::assertIsolated($app);
    if (config('database.connections.mysql.host') !== 'test-mysql'
        || config('database.connections.mysql.unix_socket') !== '') {
        throw new RuntimeException('Endpoint de pruebas incorrecto.');
    }
    config(['database.connections.mysql.prefix' => $prefix]);
    DB::purge('mysql');
    Auth::setUser(User::findOrFail(getenv('F1_TEST_USER')));
    app()->instance('currentBusinessId', getenv('F1_TEST_BUSINESS'));
    session([
        AuthContextResolver::SESSION_BUSINESS_ID => getenv('F1_TEST_BUSINESS'),
        AuthContextResolver::SESSION_BRANCH_ID => getenv('F1_TEST_BRANCH'),
    ]);
    $sale = Sale::query()->findOrFail(getenv('F1_TEST_SALE'));
    $destination = Sale::query()->findOrFail(getenv('F1_TEST_DESTINATION'));
    $item = $sale->items()->sole();
    $write = match (getenv('F1_TEST_OPERATION')) {
        'sale-save' => fn () => $sale->update(['total' => 1]),
        'sale-quiet' => fn () => $sale->forceFill(['payment_method' => 'transferencia'])->saveQuietly(),
        'bulk-sale' => fn () => Sale::query()->whereKey($sale->id)->update(['total' => 1]),
        'base-sale' => fn () => Sale::query()->whereKey($sale->id)->toBase()->update(['discount' => 1]),
        'item-save' => fn () => $item->update(['quantity' => 2]),
        'item-delete' => fn () => $item->deleteQuietly(),
        'item-create' => fn () => $sale->items()->create(['product_id' => $item->product_id, 'quantity' => 1, 'unit_price' => 50]),
        'item-transfer' => fn () => $destination->items()->save($item),
        default => throw new RuntimeException('Operación de pruebas desconocida.'),
    };
    DB::statement('SET SESSION innodb_lock_wait_timeout = 1');
    fwrite(STDOUT, 'READY:'.DB::selectOne('SELECT CONNECTION_ID() AS id')->id."\n");
    fflush(STDOUT);
    if (trim((string) fgets(STDIN)) !== 'start') {
        throw new RuntimeException('Falta señal de inicio.');
    }
    try {
        $write();
        throw new RuntimeException('La escritura no respetó el bloqueo de confirmación.');
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
    try {
        $write();
        throw new RuntimeException('La escritura aceptó el estado obsoleto.');
    } catch (ValidationException) {
        fwrite(STDOUT, "REJECTED\n");
    }
} catch (Throwable $exception) {
    fwrite(STDERR, $exception::class.': '.$exception->getMessage()."\n");
    exit(1);
}
