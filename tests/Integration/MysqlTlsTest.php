<?php

namespace Tests\Integration;

use App\Console\Commands\PortabilityBackup;
use App\Console\Commands\PortabilityRestore;
use App\Services\PdoMysqlServerIdentityReader;
use App\Services\VerifiedMysqlConnector;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\DataProvider;
use RuntimeException;
use Symfony\Component\Console\Input\ArrayInput;
use Symfony\Component\Process\Process;
use Tests\TestCase;

class MysqlTlsTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        $this->assertSame('1', getenv('MYSQL_TLS_INTEGRATION'));
        $this->assertSame('test-mysql-tls', config('database.connections.mysql.host'));
        $this->assertSame('', config('database.connections.mysql.unix_socket'));
        $this->assertEmpty(config('database.connections.mysql.url'));
        $this->assertSame('/tls-certs/ca.pem', config('database.connections.mysql.options')[\PDO::MYSQL_ATTR_SSL_CA]);
    }

    public function test_laravel_and_independent_identity_connections_use_verified_tls(): void
    {
        $status = DB::selectOne("SHOW SESSION STATUS LIKE 'Ssl_cipher'");
        $this->assertNotEmpty($status->Value);
        $config = config('database.connections.mysql');
        $identity = (new PdoMysqlServerIdentityReader)->target($config + ['socket' => '']);
        $this->assertSame(DB::selectOne('SELECT @@server_uuid AS uuid')->uuid, $identity['server_uuid']);
        $this->assertInstanceOf(VerifiedMysqlConnector::class, app('db.connector.mysql'));
    }

    #[DataProvider('tlsFailures')]
    public function test_laravel_refuses_untrusted_or_unencrypted_endpoints(array $changes): void
    {
        $config = array_replace(config('database.connections.mysql'), $changes);
        if (isset($changes['ssl_ca'])) {
            $config['options'][\PDO::MYSQL_ATTR_SSL_CA] = $changes['ssl_ca'];
        }
        $this->expectException(RuntimeException::class);
        (new VerifiedMysqlConnector)->connect($config);
    }

    #[DataProvider('tlsFailures')]
    public function test_independent_reader_refuses_untrusted_or_unencrypted_endpoints(array $changes): void
    {
        $config = array_replace(config('database.connections.mysql'), $changes);
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('No se pudo verificar');
        (new PdoMysqlServerIdentityReader)->target($config + ['socket' => '']);
    }

    public static function tlsFailures(): array
    {
        return [
            'wrong CA' => [['ssl_ca' => '/tls-certs/wrong-ca.pem']],
            'wrong hostname' => [['host' => 'wrong-mysql-tls']],
            'server without TLS' => [['host' => 'test-mysql-no-tls']],
        ];
    }

    public function test_strict_preflight_refuses_a_required_connection_without_ca(): void
    {
        config(['database.connections.mysql.options' => []]);
        $this->artisan('security:preflight', ['--strict' => true])
            ->expectsOutputToContain('CA legible')
            ->assertFailed();
    }

    #[DataProvider('clientCases')]
    public function test_private_client_files_require_verified_tls(string $source, ?array $changes): void
    {
        if ($changes) {
            config(['database.connections.mysql' => array_replace(config('database.connections.mysql'), $changes)]);
        }
        $mysql = trim((string) shell_exec('command -v mysql'));
        $dump = trim((string) shell_exec('command -v mysqldump'));
        $this->assertNotSame('', $mysql);
        $this->assertNotSame('', $dump);
        if ($source === 'backup') {
            $command = new class extends PortabilityBackup
            {
                public function file(string $binary): string
                {
                    return $this->mysqlCnf($binary);
                }
            };
            $cnf = $command->file($dump);
        } else {
            $command = new class extends PortabilityRestore
            {
                public function file(string $binary): string
                {
                    $this->input = new ArrayInput([], $this->getDefinition());

                    return $this->mysqlCnf($binary);
                }
            };
            $cnf = $command->file($mysql);
        }
        $home = null;
        try {
            if (! $changes) {
                $home = base_path('storage/framework/cache/crd-tls-home-'.bin2hex(random_bytes(8)));
                mkdir($home, 0700);
                file_put_contents($home.'/.my.cnf', "[client]\nssl=0\n");
            }
            $process = new Process([
                $mysql, '--defaults-file='.$cnf, '--batch',
                '-e', "SHOW SESSION STATUS LIKE 'Ssl_cipher'",
            ]);
            if ($home) {
                $process->setEnv(['HOME' => $home]);
            }
            $process->setTimeout(10);
            $process->run();
            if ($changes) {
                $this->assertFalse($process->isSuccessful(), 'El cliente no debe aceptar un servidor no autenticado.');
            } else {
                $this->assertTrue($process->isSuccessful(), $process->getErrorOutput());
                $this->assertMatchesRegularExpression('/^Ssl_cipher\t\S+$/m', $process->getOutput());
                $this->assertSame(0600, fileperms($cnf) & 0777);
                if ($source === 'backup') {
                    $backup = new Process([
                        $dump, '--defaults-file='.$cnf, '--no-data', '--skip-lock-tables',
                        'comercios_rd_storage_test',
                    ]);
                    $backup->setEnv(['HOME' => $home]);
                    $backup->setTimeout(10);
                    $backup->run();
                    $this->assertTrue($backup->isSuccessful(), $backup->getErrorOutput());
                }
            }
        } finally {
            unlink($cnf);
            if ($home) {
                unlink($home.'/.my.cnf');
                rmdir($home);
            }
        }
    }

    public static function clientCases(): array
    {
        $cases = [];
        foreach (['backup', 'restore'] as $source) {
            $cases[$source.' verified'] = [$source, null];
            foreach (self::tlsFailures() as $name => [$changes]) {
                $cases[$source.' '.$name] = [$source, $changes];
            }
        }

        return $cases;
    }
}
