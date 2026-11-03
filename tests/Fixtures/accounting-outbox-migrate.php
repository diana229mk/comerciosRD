<?php

use App\Support\TestingDatabaseGuard;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Support\Facades\Schema;

require dirname(__DIR__, 2).'/vendor/autoload.php';

try {
    if (getenv('APP_ENV') !== 'testing' || getenv('ACCOUNTING_QUEUE_INTEGRATION') !== '1'
        || ($argv[1] ?? '') !== '--apply') {
        throw new RuntimeException('La migración requiere el runner aislado y autorización explícita mediante --apply.');
    }
    $app = require dirname(__DIR__, 2).'/bootstrap/app.php';
    $kernel = $app->make(Kernel::class);
    $kernel->bootstrap();
    TestingDatabaseGuard::assertIsolated($app);
    if (config('database.connections.mysql.host') !== 'test-mysql'
        || config('database.connections.mysql.prefix') !== ''
        || config('database.connections.mysql.unix_socket') !== ''
        || Schema::hasTable('accounting_outbox') || Schema::hasTable('accounting_sequences')) {
        throw new RuntimeException('Identidad inesperada o tablas ya existentes; detenerse sin alterar el esquema.');
    }
    $status = $kernel->call('migrate', [
        '--path' => ['database/migrations/2026_10_29_140000_create_accounting_outbox_table.php'],
        '--force' => true,
    ]);
    fwrite(STDOUT, $kernel->output());
    exit($status);
} catch (Throwable $exception) {
    fwrite(STDERR, $exception::class.': '.$exception->getMessage()."\n");
    exit(1);
}
