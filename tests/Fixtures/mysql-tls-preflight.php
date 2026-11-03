<?php

use App\Support\TestingDatabaseGuard;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Support\Facades\DB;

require dirname(__DIR__, 2).'/vendor/autoload.php';

try {
    if (getenv('APP_ENV') !== 'testing' || getenv('MYSQL_TLS_INTEGRATION') !== '1') {
        throw new RuntimeException('Este preflight requiere el perfil TLS aislado.');
    }
    $app = require dirname(__DIR__, 2).'/bootstrap/app.php';
    $app->make(Kernel::class)->bootstrap();
    if (config('database.connections.mysql.host') !== 'test-mysql-tls'
        || config('database.connections.mysql.unix_socket') !== ''
        || config('database.connections.mysql.prefix') !== ''
        || config('database.connections.mysql.options')[PDO::MYSQL_ATTR_SSL_CA] !== '/tls-certs/ca.pem') {
        throw new RuntimeException('Endpoint o CA inesperados.');
    }
    TestingDatabaseGuard::assertIsolated($app);
    $secureConfig = config('database.connections.mysql');
    $output = [
        'guard' => 'PASS',
        'tls' => DB::selectOne("SHOW SESSION STATUS LIKE 'Ssl_cipher'"),
        'mysql' => DB::selectOne('SELECT DATABASE() AS db, @@hostname AS host, @@server_uuid AS uuid, CURRENT_USER() AS account, VERSION() AS version'),
        'ca_sha256' => hash_file('sha256', '/tls-certs/ca.pem'),
    ];
    $config = $secureConfig;
    $config['host'] = 'test-mysql-no-tls';
    $config['ssl_required'] = false;
    $config['options'] = [];
    config(['database.connections.mysql' => $config]);
    DB::purge('mysql');
    try {
        TestingDatabaseGuard::assertIsolated($app);
        $negative = DB::connection('mysql');
        $identity = $negative->selectOne('SELECT DATABASE() AS db, @@hostname AS host, @@server_uuid AS uuid, CURRENT_USER() AS account');
        $cipher = $negative->selectOne("SHOW SESSION STATUS LIKE 'Ssl_cipher'");
        if ($identity->account !== 'comercios_storage_test@%' || $cipher->Value !== ''
            || $identity->uuid === $output['mysql']->uuid) {
            throw new RuntimeException('Servidor negativo fuera del entorno de pruebas o no distinto/sin TLS.');
        }
        $output['no_tls_guard'] = 'PASS';
        $output['no_tls_mysql'] = $identity;
        $output['no_tls_status'] = $cipher;
        $output['no_tls_grants'] = $negative->select('SHOW GRANTS');
    } finally {
        config(['database.connections.mysql' => $secureConfig]);
        DB::purge('mysql');
    }
    fwrite(STDOUT, json_encode($output, JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR)."\n");
} catch (Throwable $exception) {
    fwrite(STDERR, $exception::class.': '.$exception->getMessage()."\n");
    exit(1);
}
