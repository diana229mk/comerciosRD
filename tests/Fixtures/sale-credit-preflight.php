<?php

use App\Support\TestingDatabaseGuard;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Support\Facades\DB;

require dirname(__DIR__, 2).'/vendor/autoload.php';

try {
    if (getenv('APP_ENV') !== 'testing' || getenv('ACCOUNTING_QUEUE_INTEGRATION') !== '1'
        || getenv('INF05_F2_PREFLIGHT') !== '1') {
        throw new RuntimeException('Este preflight requiere autorización explícita y el runner aislado F2.');
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
        throw new RuntimeException('MySQL/Redis fuera de los endpoints aislados permitidos.');
    }
    $requiredSchema = getenv('INF05_F2_SCHEMA') === '1';
    $tables = [
        'users', 'businesses', 'user_business', 'branches', 'accounts', 'customers',
        'products', 'product_batches', 'sales', 'sale_items', 'sale_item_batches',
        'inventory_movements', 'sales_daily', 'cash_sessions', 'journal_entries',
        'journal_entry_lines', 'failed_jobs', 'accounting_outbox', 'accounting_sequences',
        'receivables', 'receivable_payments',
    ];
    if ($requiredSchema) {
        $tables[] = 'sale_checkout_requests';
    }
    $engines = DB::table('information_schema.tables')->whereRaw('TABLE_SCHEMA = DATABASE()')
        ->whereIn('TABLE_NAME', $tables)->pluck('ENGINE', 'TABLE_NAME')->all();
    foreach ($tables as $table) {
        if (strtolower((string) ($engines[$table] ?? '')) !== 'innodb') {
            throw new RuntimeException("Falta la tabla InnoDB {$table}; no continuar.");
        }
    }
    $columns = DB::table('information_schema.columns')->whereRaw('TABLE_SCHEMA = DATABASE()')
        ->whereIn('TABLE_NAME', ['sales', 'receivables', 'sale_checkout_requests'])
        ->get()->groupBy('TABLE_NAME')->map(fn ($rows) => $rows->keyBy('COLUMN_NAME'));
    $indexes = DB::table('information_schema.statistics')->whereRaw('TABLE_SCHEMA = DATABASE()')
        ->whereIn('TABLE_NAME', ['sales', 'receivables', 'sale_checkout_requests'])
        ->where('NON_UNIQUE', 0)->orderBy('SEQ_IN_INDEX')->get();
    $foreignKeys = DB::table('information_schema.key_column_usage')->whereRaw('TABLE_SCHEMA = DATABASE()')
        ->whereIn('TABLE_NAME', ['receivables', 'sale_checkout_requests'])
        ->whereNotNull('REFERENCED_TABLE_NAME')->orderBy('ORDINAL_POSITION')->get();
    $foreignRules = DB::table('information_schema.referential_constraints')
        ->whereRaw('CONSTRAINT_SCHEMA = DATABASE()')
        ->whereIn('TABLE_NAME', ['receivables', 'sale_checkout_requests'])->get();
    $checks = DB::select(
        'SELECT c.CONSTRAINT_NAME, c.CHECK_CLAUSE, t.ENFORCED
         FROM information_schema.CHECK_CONSTRAINTS c
         JOIN information_schema.TABLE_CONSTRAINTS t
           ON t.CONSTRAINT_SCHEMA = c.CONSTRAINT_SCHEMA AND t.CONSTRAINT_NAME = c.CONSTRAINT_NAME
         WHERE c.CONSTRAINT_SCHEMA = DATABASE() AND t.TABLE_NAME = ?',
        ['receivables'],
    );
    if ($requiredSchema) {
        foreach (['sales' => ['credit_version', 'credit_effects_at'],
            'receivables' => ['sale_id', 'original_amount'],
            'sale_checkout_requests' => ['id', 'business_id', 'request_key', 'payload_hash', 'sale_id']] as $table => $names) {
            foreach ($names as $name) {
                if (! isset($columns[$table][$name])) {
                    throw new RuntimeException("Contrato F2 incompleto: {$table}.{$name}.");
                }
            }
        }
        foreach (['amount', 'paid_amount', 'balance', 'original_amount'] as $name) {
            $column = $columns['receivables'][$name];
            if ($column->DATA_TYPE !== 'decimal' || (int) $column->NUMERIC_PRECISION !== 14
                || (int) $column->NUMERIC_SCALE !== 2
                || $column->IS_NULLABLE !== ($name === 'original_amount' ? 'YES' : 'NO')) {
                throw new RuntimeException("Precisión incorrecta: receivables.{$name}.");
            }
            foreach ([
                ['sales', 'credit_version', 'smallint unsigned'],
                ['sales', 'credit_effects_at', 'timestamp'],
                ['receivables', 'sale_id', 'char(36)'],
                ['sale_checkout_requests', 'sale_id', 'char(36)'],
            ] as [$table, $name, $type]) {
                $column = $columns[$table][$name];
                if ($column->IS_NULLABLE !== 'YES' || $column->COLUMN_TYPE !== $type) {
                    throw new RuntimeException("Tipo/compatibilidad heredada incorrectos: {$table}.{$name}.");
                }
            }
        }
        foreach (['request_key' => 100, 'payload_hash' => 64] as $name => $length) {
            $column = $columns['sale_checkout_requests'][$name];
            if ($column->COLLATION_NAME !== 'ascii_bin' || (int) $column->CHARACTER_MAXIMUM_LENGTH !== $length) {
                throw new RuntimeException("Identidad de solicitud incompatible: {$name}.");
            }
        }
        foreach ([
            ['sales', 'sales_business_identity_unique', ['business_id', 'id']],
            ['receivables', 'receivables_business_sale_unique', ['business_id', 'sale_id']],
            ['sale_checkout_requests', 'sale_checkout_business_key_unique', ['business_id', 'request_key']],
        ] as [$table, $name, $expected]) {
            $actual = $indexes->where('TABLE_NAME', $table)->where('INDEX_NAME', $name)->pluck('COLUMN_NAME')->all();
            if ($actual !== $expected) {
                throw new RuntimeException("Falta la unicidad SQL {$name}.");
            }
        }
        foreach ([
            ['receivables', 'receivables_sale_business_foreign'],
            ['sale_checkout_requests', 'sale_checkout_sale_business_foreign'],
        ] as [$table, $name]) {
            $actual = $foreignKeys->where('TABLE_NAME', $table)->where('CONSTRAINT_NAME', $name);
            $rules = $foreignRules->where('TABLE_NAME', $table)->firstWhere('CONSTRAINT_NAME', $name);
            if ($actual->pluck('COLUMN_NAME')->all() !== ['business_id', 'sale_id']
                || $actual->pluck('REFERENCED_COLUMN_NAME')->all() !== ['business_id', 'id']
                || $actual->pluck('REFERENCED_TABLE_NAME')->unique()->values()->all() !== ['sales']
                || $actual->pluck('REFERENCED_TABLE_SCHEMA')->unique()->values()->all() !== [DB::connection()->getDatabaseName()]
                || ! $rules || $rules->DELETE_RULE !== 'RESTRICT' || $rules->UPDATE_RULE !== 'RESTRICT') {
                throw new RuntimeException("Falta la FK empresarial {$name}.");
            }
            $businessFk = $foreignKeys->where('TABLE_NAME', 'sale_checkout_requests')
                ->where('REFERENCED_TABLE_NAME', 'businesses');
            if ($businessFk->pluck('COLUMN_NAME')->all() !== ['business_id']
                || $businessFk->pluck('REFERENCED_COLUMN_NAME')->all() !== ['id']) {
                throw new RuntimeException('Falta la FK empresarial de la solicitud.');
            }
        }
        $check = collect($checks)->firstWhere('CONSTRAINT_NAME', 'receivables_source_check');
        $normalize = fn ($value) => strtolower(preg_replace('/[\s`()]/', '', $value));
        if (! $check || $check->ENFORCED !== 'YES'
            || $normalize($check->CHECK_CLAUSE) !== $normalize(
                '(sale_id IS NULL AND original_amount IS NULL) OR '
                .'(sale_id IS NOT NULL AND original_amount IS NOT NULL AND original_amount >= 0)'
            )) {
            throw new RuntimeException('CHECK de identidad/original no está aplicado y exigido.');
        }
    }
    $identity = DB::selectOne('SELECT DATABASE() AS db, @@hostname AS host, @@server_uuid AS uuid, '
        .'CURRENT_USER() AS account, VERSION() AS version, CONNECTION_ID() AS connection_id');
    if (! str_starts_with($identity->version, '8.4.')) {
        throw new RuntimeException('El contrato de validación requiere MySQL 8.4.');
    }
    $grants = array_map(fn ($row) => (string) array_values((array) $row)[0], DB::select('SHOW GRANTS'));
    foreach ($grants as $grant) {
        if (str_contains($grant, 'WITH GRANT OPTION')
            || (str_contains($grant, ' ON *.* ') && ! str_starts_with($grant, 'GRANT USAGE ON '))
            || (! str_contains($grant, ' ON *.* ') && ! str_contains($grant, ' ON `comercios_rd_storage_test`.* '))) {
            throw new RuntimeException('Grants fuera del esquema aislado.');
        }
    }
    $redis = $app['redis']->connection();
    $info = $redis->info('server');
    fwrite(STDOUT, json_encode([
        'guard' => 'PASS', 'scope' => 'INF-05/F2 read-only preflight',
        'mysql' => $identity, 'grants' => $grants, 'transactional_engines' => $engines,
        'schema_contract' => $requiredSchema ? 'PASS' : 'NOT_VALIDATED_BASELINE_ONLY',
        'f2_columns_present' => collect([
            'sales.credit_version' => isset($columns['sales']['credit_version']),
            'receivables.sale_id' => isset($columns['receivables']['sale_id']),
            'sale_checkout_requests.request_key' => isset($columns['sale_checkout_requests']['request_key']),
        ])->all(),
        'redis' => ['host' => 'test-redis', 'database' => 15, 'ping' => $redis->ping(), 'run_id' => $info['run_id']],
        'phpunit_executed' => false, 'migrations_executed' => false,
        'docker_topology_requires_separate_inspection' => true,
    ], JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR)."\n");
} catch (Throwable $exception) {
    fwrite(STDERR, $exception::class.': '.$exception->getMessage()."\n");
    exit(1);
}
