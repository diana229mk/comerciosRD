<?php

use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Support\Facades\DB;
use Symfony\Component\Process\Process;

try {
    if (getenv('INF05_F2_MIGRATE') !== '1' || getenv('INF05_F2_SCHEMA') !== '0') {
        throw new RuntimeException('Falta autorización de migración F2 con baseline previo.');
    }

    // This file may be streamed to the existing isolated image through stdin.
    require getcwd().'/tests/Fixtures/sale-credit-preflight.php';

    $connection = DB::connection('mysql');
    $connection->setReconnector(function (): never {
        throw new RuntimeException('Conexión perdida: detener sin reconectar ni reintentar.');
    });
    $identity = $connection->selectOne(
        'SELECT DATABASE() AS db, @@hostname AS host, @@server_uuid AS uuid, CURRENT_USER() AS account'
    );
    if ($identity->db !== 'comercios_rd_storage_test' || $identity->host !== 'ef3c89813c5c'
        || $identity->uuid !== 'bfd92f30-d1c5-11f1-beb5-b24b172266ee'
        || $identity->account !== 'comercios_storage_test@%'
        || $connection->transactionLevel() !== 0
        || $connection->getConfig('charset') !== 'utf8mb4'
        || $connection->getConfig('collation') !== 'utf8mb4_unicode_ci'
        || config('database.migrations.table') !== 'migrations'
        || config('database.connections.mysql.read') || config('database.connections.mysql.write')) {
        throw new RuntimeException('Identidad, repositorio o conexión fuera del contrato autorizado.');
    }
    foreach (DB::getConnections() as $name => $open) {
        if ($name !== 'mysql' || $open !== $connection || $open->getDatabaseName() !== 'comercios_rd_storage_test') {
            throw new RuntimeException('Existe otra conexión en el proceso; no se aplicará DDL.');
        }
    }
    $connection->listen(function (QueryExecuted $query) use ($connection): void {
        if ($query->connection !== $connection
            || $query->connection->getDatabaseName() !== 'comercios_rd_storage_test') {
            throw new RuntimeException('La operación salió de la conexión aislada autorizada.');
        }
    });
    if ((int) $connection->selectOne(
        'SELECT GET_LOCK(?, 0) AS acquired', ['comercios_rd_storage_test:inf05-f2']
    )->acquired !== 1) {
        throw new RuntimeException('Otro ejecutor F2 tiene el bloqueo; detener sin esperar ni reintentar.');
    }
    $schema = $connection->getSchemaBuilder();
    $migration = '2026_11_02_120000_add_sale_credit_integrity';
    $path = base_path('database/migrations/'.$migration.'.php');
    $hash = getenv('INF05_F2_MIGRATION_SHA256');
    if (! is_string($hash) || preg_match('/\A[a-f0-9]{64}\z/D', $hash) !== 1
        || ! hash_equals($hash, hash_file('sha256', $path))) {
        throw new RuntimeException('La migración de la imagen no coincide con el archivo local revisado.');
    }
    if (! $schema->hasTable('migrations')
        || ! $connection->table('migrations')->exists()
        || $connection->table('migrations')->where('migration', $migration)->exists()
        || $schema->hasTable('sale_checkout_requests')) {
        throw new RuntimeException('Repositorio o tabla F2 inesperados; no aplicar ni repetir DDL.');
    }
    foreach (['sales' => ['credit_version', 'credit_effects_at'],
        'receivables' => ['sale_id', 'original_amount']] as $table => $columns) {
        foreach ($columns as $column) {
            if ($schema->hasColumn($table, $column)) {
                throw new RuntimeException("DDL parcial: existe {$table}.{$column}.");
            }
        }
    }
    $names = [
        'sales_business_identity_unique', 'receivables_business_sale_unique',
        'receivables_sale_business_foreign', 'receivables_source_check',
        'sale_checkout_business_key_unique', 'sale_checkout_sale_business_foreign',
        'sale_checkout_requests_business_id_foreign',
    ];
    if ($connection->table('information_schema.table_constraints')
        ->whereRaw('CONSTRAINT_SCHEMA = DATABASE()')->whereIn('CONSTRAINT_NAME', $names)->exists()
        || $connection->table('information_schema.statistics')
            ->whereRaw('TABLE_SCHEMA = DATABASE()')->whereIn('INDEX_NAME', $names)->exists()) {
        throw new RuntimeException('Ya existe un índice/constraint F2 en el esquema; detener y revisar.');
    }
    $columns = $connection->table('information_schema.columns')->whereRaw('TABLE_SCHEMA = DATABASE()')
        ->whereIn('TABLE_NAME', ['businesses', 'sales', 'receivables'])->get()->groupBy('TABLE_NAME')
        ->map(fn ($rows) => $rows->keyBy('COLUMN_NAME'));
    foreach (['businesses' => ['id'], 'sales' => ['id', 'business_id'],
        'receivables' => ['id', 'business_id']] as $table => $fields) {
        foreach ($fields as $field) {
            $column = $columns[$table][$field] ?? null;
            if (! $column || $column->COLUMN_TYPE !== 'char(36)'
                || $column->COLLATION_NAME !== 'utf8mb4_unicode_ci' || $column->IS_NULLABLE !== 'NO') {
                throw new RuntimeException("Identidad SQL incompatible: {$table}.{$field}.");
            }
        }
    }
    foreach (['amount', 'paid_amount', 'balance'] as $name) {
        $column = $columns['receivables'][$name] ?? null;
        if (! $column || $column->COLUMN_TYPE !== 'decimal(12,2)' || $column->IS_NULLABLE !== 'NO'
            || ($name === 'amount' ? $column->COLUMN_DEFAULT !== null : $column->COLUMN_DEFAULT !== '0.00')) {
            throw new RuntimeException("DDL parcial o precisión/default inesperado: receivables.{$name}.");
        }
    }
    fwrite(STDOUT, "F2_PRE_DDL_GUARD=PASS\nOPEN_CONNECTIONS=mysql:comercios_rd_storage_test\n");
    $migrator = app('migrator');
    $migrator->setConnection('mysql');
    if ($migrator->getMigrationFiles([$path]) !== [$migration => $path]) {
        throw new RuntimeException('El alcance no contiene exactamente el archivo autorizado.');
    }
    $executed = $migrator->run([$path], ['pretend' => false, 'step' => true]);
    if ($executed !== [$path] || $connection->table('migrations')->where('migration', $migration)->count() !== 1) {
        throw new RuntimeException('Resultado de migración inesperado; no repetir automáticamente.');
    }
    fwrite(STDOUT, "F2_MIGRATION=APPLIED\n");

    $preflight = new Process([PHP_BINARY, base_path('tests/Fixtures/sale-credit-preflight.php')],
        base_path(), ['INF05_F2_SCHEMA' => '1'], timeout: 60);
    $status = $preflight->run();
    fwrite(STDOUT, $preflight->getOutput());
    fwrite(STDERR, $preflight->getErrorOutput());
    if ($status !== 0) {
        throw new RuntimeException('Falló el preflight de esquema posterior; detener sin reintentar.');
    }
    foreach (['sales', 'receivables', 'sale_checkout_requests'] as $table) {
        $definition = $connection->selectOne("SHOW CREATE TABLE `{$table}`");
        fwrite(STDOUT, "\nTABLE: {$table}\n".array_values((array) $definition)[1].";\n");
    }
    fwrite(STDOUT, json_encode([
        'migration' => $migration, 'migration_sha256' => $hash,
        'migration_record' => $connection->table('migrations')->where('migration', $migration)->get()->all(),
        'schema_preflight' => 'PASS', 'phpunit_executed' => false,
        'historical_backfill_executed' => false, 'status' => 'PENDING_FUNCTIONAL_VALIDATION',
    ], JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR)."\n");
} catch (Throwable $exception) {
    fwrite(STDERR, $exception::class.': '.$exception->getMessage()
        ."\nSTOP: no reintentar. MySQL DDL puede haber quedado parcialmente aplicado.\n");
    exit(1);
}
