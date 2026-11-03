<?php

use App\Support\TestingDatabaseGuard;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

require dirname(__DIR__, 2).'/vendor/autoload.php';

try {
    if (getenv('APP_ENV') !== 'testing' || getenv('ACCOUNTING_QUEUE_INTEGRATION') !== '1'
        || getenv('INF05_F1_PREFLIGHT') !== '1') {
        throw new RuntimeException('Se requiere autorización del preflight F1 y el runner contable aislado.');
    }
    $app = require dirname(__DIR__, 2).'/bootstrap/app.php';
    $app->make(Kernel::class)->bootstrap();
    TestingDatabaseGuard::assertIsolated($app);
    if (config('database.connections.mysql.host') !== 'test-mysql'
        || config('database.connections.mysql.unix_socket') !== ''
        || config('database.connections.mysql.prefix') !== ''
        || config('database.redis.default.host') !== 'test-redis'
        || filled(config('database.redis.default.url'))
        || (string) config('database.redis.default.database') !== '15'
        || (string) config('database.redis.default.port') !== '6379') {
        throw new RuntimeException('Endpoints fuera del contrato de pruebas aisladas.');
    }
    $tables = [
        'users', 'businesses', 'user_business', 'branches', 'accounts',
        'products', 'product_batches', 'sales', 'sale_items', 'sale_item_batches',
        'inventory_movements', 'sales_daily', 'cash_sessions', 'journal_entries',
        'journal_entry_lines', 'failed_jobs', 'accounting_outbox', 'accounting_sequences',
    ];
    $engines = DB::table('information_schema.tables')->whereRaw('TABLE_SCHEMA = DATABASE()')
        ->whereIn('TABLE_NAME', $tables)->pluck('ENGINE', 'TABLE_NAME')->all();
    foreach ($tables as $table) {
        if (strtolower((string) ($engines[$table] ?? '')) !== 'innodb') {
            throw new RuntimeException("Falta la tabla InnoDB {$table}; no ejecutar pruebas ni migraciones.");
        }
    }
    foreach ([
        'sales' => ['id', 'business_id', 'status', 'payment_method', 'subtotal', 'discount',
            'tax_rate', 'tax', 'total', 'amount_paid', 'sale_date', 'sold_at', 'sale_number', 'number', 'deleted_at'],
        'sale_items' => ['id', 'business_id', 'sale_id', 'product_id', 'quantity', 'unit_price', 'unit_cost', 'subtotal', 'line_total'],
    ] as $table => $columns) {
        foreach ($columns as $column) {
            if (! Schema::hasColumn($table, $column)) {
                throw new RuntimeException("Falta {$table}.{$column}; no ejecutar pruebas ni migraciones.");
            }
        }
    }
    $identity = DB::selectOne('SELECT DATABASE() AS db, @@hostname AS host, @@server_uuid AS uuid, '
        .'CURRENT_USER() AS account, VERSION() AS version, CONNECTION_ID() AS connection_id');
    $grants = array_map(fn ($row) => (string) array_values((array) $row)[0], DB::select('SHOW GRANTS'));
    foreach ($grants as $grant) {
        if (str_contains($grant, 'WITH GRANT OPTION')
            || (str_contains($grant, ' ON *.* ') && ! str_starts_with($grant, 'GRANT USAGE ON '))
            || (! str_contains($grant, ' ON *.* ')
                && ! str_contains($grant, ' ON `comercios_rd_storage_test`.* '))) {
            throw new RuntimeException('Grants fuera del esquema aislado.');
        }
    }
    $redis = $app['redis']->connection();
    $info = $redis->info('server');
    fwrite(STDOUT, json_encode([
        'guard' => 'PASS', 'scope' => 'INF-05/F1 read-only preflight',
        'mysql' => $identity, 'grants' => $grants, 'transactional_engines' => $engines,
        'redis' => ['host' => 'test-redis', 'database' => 15, 'ping' => $redis->ping(), 'run_id' => $info['run_id']],
        'phpunit_executed' => false, 'migrations_executed' => false,
        'docker_topology_requires_separate_inspection' => true,
    ], JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR)."\n");
} catch (Throwable $exception) {
    fwrite(STDERR, $exception::class.': '.$exception->getMessage()."\n");
    exit(1);
}
