<?php

use App\Support\TestingDatabaseGuard;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

require dirname(__DIR__, 2).'/vendor/autoload.php';

try {
    if (getenv('APP_ENV') !== 'testing'
        || getenv('ACCOUNTING_QUEUE_INTEGRATION') !== '1') {
        throw new RuntimeException('Este preflight requiere el perfil contable aislado.');
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
        throw new RuntimeException('Los endpoints no corresponden al runner aislado.');
    }
    $identity = DB::selectOne(
        'SELECT DATABASE() AS db, @@hostname AS host, @@server_uuid AS uuid, '
        .'CURRENT_USER() AS account, VERSION() AS version'
    );
    $missing = [];
    foreach ([
        'users', 'businesses', 'user_business', 'branches', 'accounts',
        'products', 'product_batches', 'sales', 'sale_items', 'sale_item_batches',
        'inventory_movements', 'sales_daily', 'cash_sessions',
        'journal_entries', 'journal_entry_lines', 'failed_jobs',
    ] as $table) {
        if (! Schema::hasTable($table)) {
            $missing[] = $table;
        }
    }
    $redis = $app['redis']->connection();
    $info = $redis->info('server');
    $engines = DB::table('information_schema.tables')
        ->whereRaw('TABLE_SCHEMA = DATABASE()')
        ->whereIn('TABLE_NAME', [
            'sales', 'sale_items', 'journal_entries', 'journal_entry_lines', 'accounting_outbox', 'accounting_sequences',
        ])->pluck('ENGINE', 'TABLE_NAME')->all();
    foreach ($engines as $table => $engine) {
        if (strtolower((string) $engine) !== 'innodb') {
            throw new RuntimeException("La tabla {$table} no es transaccional InnoDB.");
        }
    }
    $output = [
        'guard' => 'PASS',
        'environment' => $app->environment(),
        'mysql_host' => config('database.connections.mysql.host'),
        'mysql' => $identity,
        'missing_source_tables' => $missing,
        'outbox_schema_present' => Schema::hasTable('accounting_outbox') && Schema::hasTable('accounting_sequences'),
        'outbox_schema_tables' => [
            'accounting_outbox' => Schema::hasTable('accounting_outbox'),
            'accounting_sequences' => Schema::hasTable('accounting_sequences'),
        ],
        'transactional_engines' => $engines,
        'redis' => [
            'host' => config('database.redis.default.host'),
            'database' => config('database.redis.default.database'),
            'ping' => $redis->ping(),
            'run_id' => $info['run_id'],
            'version' => $info['redis_version'],
        ],
    ];
    fwrite(STDOUT, json_encode($output, JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR)."\n");
    if ($missing !== []) {
        throw new RuntimeException('Faltan tablas de referencia; no ejecutar pruebas ni migraciones.');
    }
} catch (Throwable $exception) {
    fwrite(STDERR, $exception::class.': '.$exception->getMessage()."\n");
    exit(1);
}
