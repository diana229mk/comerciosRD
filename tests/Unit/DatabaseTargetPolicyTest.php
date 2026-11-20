<?php

namespace Tests\Unit;

use App\Support\DatabaseTargetPolicy;
use App\Support\DatabaseTargetViolation;
use App\Support\ProcessEnvironment;
use App\Support\TestRuntime;
use Illuminate\Filesystem\Filesystem;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Tests\Support\PreBootGuard;

class DatabaseTargetPolicyTest extends TestCase
{
    protected function tearDown(): void
    {
        ProcessEnvironment::fake(null);
        parent::tearDown();
    }

    public function test_the_isolated_test_connection_is_accepted(): void
    {
        DatabaseTargetPolicy::assertTestingConnection(self::isolated());

        $this->addToAssertionCount(1);
    }

    #[DataProvider('rejectedConnections')]
    public function test_real_ambiguous_or_alternative_targets_are_rejected(array $changes): void
    {
        $this->expectException(DatabaseTargetViolation::class);

        DatabaseTargetPolicy::assertTestingConnection(array_replace(self::isolated(), $changes));
    }

    public static function rejectedConnections(): array
    {
        return [
            'real database' => [['database' => 'comercios_rd']],
            'host loopback' => [['host' => '127.0.0.1']],
            'host gateway' => [['host' => 'host.docker.internal']],
            'unix socket' => [['unix_socket' => '/run/mysqld/mysqld.sock']],
            'DB_URL to real database' => [['url' => 'mysql://comercios_user:x@127.0.0.1:3306/comercios_rd']],
            'DB_URL to isolated database' => [['url' => 'mysql://comercios_storage_test:x@test-mysql:3306/comercios_rd_storage_test']],
            'sqlite driver' => [['driver' => 'sqlite']],
            'root account' => [['username' => 'root']],
            'development account' => [['username' => 'comercios_user']],
            'backup account' => [['username' => 'comercios_backup_ro']],
            'missing password' => [['password' => '']],
            'unresolved placeholder' => [['password' => '${COMERCIOS_TEST_DB_PASSWORD}']],
            'other port' => [['port' => '3307']],
            'empty host' => [['host' => '']],
            'read replica on host' => [['read' => ['host' => ['test-mysql', '127.0.0.1']]]],
            'write database override' => [['write' => ['database' => 'comercios_rd']]],
            'non scalar database' => [['database' => ['comercios_rd_storage_test']]],
        ];
    }

    public function test_the_runtime_contract_requires_the_explicit_variable(): void
    {
        ProcessEnvironment::fake([]);

        $this->assertNotNull(TestRuntime::failure());
        $this->expectException(DatabaseTargetViolation::class);
        DatabaseTargetPolicy::assertTestingConnection(self::isolated());
    }

    public function test_the_runtime_contract_rejects_a_marker_not_owned_by_root(): void
    {
        $marker = sys_get_temp_dir().'/crd-runtime-'.bin2hex(random_bytes(6)).'.json';
        file_put_contents($marker, json_encode(['id' => TestRuntime::ID]));
        chmod($marker, 0444);
        if (fileowner($marker) === 0) {
            @unlink($marker);
            $this->markTestSkipped('El proceso corre como root; no se puede crear un marcador ajeno a root.');
        }

        try {
            $this->assertStringContainsString('root', (string) TestRuntime::failure(TestRuntime::ID, $marker, $marker));
        } finally {
            @unlink($marker);
        }
    }

    public function test_external_endpoints_are_limited_to_the_isolated_network(): void
    {
        DatabaseTargetPolicy::assertTestingEndpoint('test-mysql', '3306', '');

        foreach ([['127.0.0.1', '3306', ''], ['test-mysql', '3306', '/run/mysqld/mysqld.sock'], ['localhost', '3306', '']] as $endpoint) {
            try {
                DatabaseTargetPolicy::assertTestingEndpoint(...$endpoint);
                $this->fail('Endpoint aceptado: '.implode(' ', $endpoint));
            } catch (DatabaseTargetViolation) {
                $this->addToAssertionCount(1);
            }
        }
    }

    public function test_approval_label_identifies_a_single_effective_target(): void
    {
        $this->assertSame('comercios_rd@127.0.0.1', DatabaseTargetPolicy::approvalLabel(
            ['driver' => 'mysql', 'host' => '127.0.0.1', 'database' => 'comercios_rd', 'unix_socket' => '']
        ));
        $this->assertSame('comercios_rd@unix:/run/mysqld/mysqld.sock', DatabaseTargetPolicy::approvalLabel(
            ['driver' => 'mysql', 'host' => '127.0.0.1', 'database' => 'comercios_rd', 'unix_socket' => '/run/mysqld/mysqld.sock']
        ));
        $this->assertSame('comercios_rd@10.0.0.9', DatabaseTargetPolicy::approvalLabel(
            ['driver' => 'mysql', 'url' => 'mysql://u:p@10.0.0.9:3306/comercios_rd', 'host' => 'ignored', 'database' => 'x']
        ));

        $this->expectException(DatabaseTargetViolation::class);
        DatabaseTargetPolicy::approvalLabel(
            ['driver' => 'mysql', 'host' => 'a', 'database' => 'comercios_rd', 'read' => ['host' => 'b']]
        );
    }

    public function test_protected_targets_are_detected_through_overrides(): void
    {
        $this->assertTrue(DatabaseTargetPolicy::isProtectedDatabase(['database' => 'x', 'write' => ['database' => 'comercios_rd']]));
        $this->assertTrue(DatabaseTargetPolicy::isProtectedServer('81F5BBF6-224A-11F1-AD19-D8808330071F'));
        $this->assertFalse(DatabaseTargetPolicy::isProtectedServer('93cfb391-e3d4-11f1-8d4b-062085bef20e'));
    }

    public function test_preboot_guard_accepts_only_the_isolated_environment(): void
    {
        PreBootGuard::assertTestProcess(self::env([]));
        $this->addToAssertionCount(1);

        foreach ([
            ['APP_ENV' => 'local'],
            ['DB_CONNECTION' => 'sqlite'],
            ['DB_HOST' => '127.0.0.1'],
            ['DB_DATABASE' => 'comercios_rd'],
            ['DB_URL' => 'mysql://u:p@127.0.0.1/comercios_rd'],
            ['DB_SOCKET' => '/run/mysqld/mysqld.sock'],
            ['DB_PORT' => '3307'],
            ['DB_TEST_USERNAME' => 'root'],
        ] as $override) {
            try {
                PreBootGuard::assertTestProcess(self::env($override));
                $this->fail('Entorno aceptado: '.json_encode($override));
            } catch (DatabaseTargetViolation) {
                $this->addToAssertionCount(1);
            }
        }
    }

    public function test_preboot_guard_rejects_cached_configuration(): void
    {
        $base = dirname(__DIR__, 2).'/storage/framework/cache/crd-preboot-'.bin2hex(random_bytes(6));
        mkdir($base.'/bootstrap/cache', 0700, true);
        file_put_contents($base.'/bootstrap/cache/config.php', '<?php return [];');

        try {
            $this->expectException(DatabaseTargetViolation::class);
            PreBootGuard::assertTestProcess(self::env([]), $base);
        } finally {
            (new Filesystem)->deleteDirectory($base);
        }
    }

    private static function isolated(): array
    {
        return [
            'driver' => 'mysql',
            'url' => '',
            'host' => 'test-mysql',
            'port' => '3306',
            'database' => 'comercios_rd_storage_test',
            'username' => 'comercios_storage_test',
            'password' => 'isolated-secret',
            'unix_socket' => '',
        ];
    }

    private static function env(array $overrides): callable
    {
        $values = array_replace([
            'APP_ENV' => 'testing',
            'DB_CONNECTION' => 'mysql',
            'DB_HOST' => 'test-mysql',
            'DB_PORT' => '3306',
            'DB_DATABASE' => 'comercios_rd_storage_test',
            'DB_URL' => '',
            'DB_SOCKET' => '',
            'DB_TEST_USERNAME' => 'comercios_storage_test',
        ], $overrides);

        return static fn (string $key): string|false => $values[$key] ?? false;
    }
}
