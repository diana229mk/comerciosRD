<?php

namespace Tests\Feature;

use PDO;
use Tests\TestCase;

class SecurityPreflightTest extends TestCase
{
    private string $ca = '';

    protected function tearDown(): void
    {
        if ($this->ca !== '' && is_file($this->ca)) {
            unlink($this->ca);
        }

        parent::tearDown();
    }

    public function test_production_preflight_passes_for_the_first_scalable_profile(): void
    {
        $this->configureProduction();

        $this->artisan('security:preflight --strict')
            ->expectsOutputToContain('Todos los chequeos de seguridad pasaron')
            ->assertExitCode(0);
    }

    public function test_production_preflight_rejects_a_test_database_and_missing_proxy(): void
    {
        $this->configureProduction([
            'database.connections.mysql.database' => 'comercios_rd_storage_test',
            'security.trusted_proxies' => '',
        ]);

        $this->artisan('security:preflight --strict')
            ->expectsOutputToContain('DB_DATABASE=comercios_rd_storage_test')
            ->expectsOutputToContain('TRUSTED_PROXIES está vacío')
            ->assertExitCode(1);
    }

    public function test_object_storage_requires_a_verified_backup_and_separate_buckets(): void
    {
        $this->configureProduction([
            'filesystems.disks.private.driver' => 's3',
            'filesystems.disks.private.bucket' => 'comercios-private',
            'filesystems.disks.public.driver' => 's3',
            'filesystems.disks.public.bucket' => 'comercios-private',
            'portability.object_backup_verified' => false,
        ]);

        $this->artisan('security:preflight --strict')
            ->expectsOutputToContain('PORTABILITY_OBJECT_BACKUP_VERIFIED')
            ->expectsOutputToContain('no pueden ser el mismo')
            ->assertExitCode(1);
    }

    public function test_verified_object_storage_is_allowed_when_buckets_differ(): void
    {
        $this->configureProduction([
            'filesystems.disks.private.driver' => 's3',
            'filesystems.disks.private.bucket' => 'comercios-private',
            'filesystems.disks.public.driver' => 's3',
            'filesystems.disks.public.bucket' => 'comercios-public',
            'portability.object_backup_verified' => true,
            'scale.target_concurrent_users' => 10000,
            'session.driver' => 'redis',
            'session.encrypt' => true,
            'cache.default' => 'redis',
            'queue.default' => 'redis',
            'scale.post_sale_queue' => true,
        ]);

        $this->artisan('security:preflight --strict')->assertExitCode(0);
    }

    public function test_a_thousand_users_on_local_disks_warns_before_a_second_server(): void
    {
        $this->configureProduction([
            'scale.target_concurrent_users' => 1000,
        ]);

        $this->artisan('security:preflight --strict')
            ->expectsOutputToContain('segundo servidor web')
            ->assertExitCode(0);
    }

    /**
     * @param  array<string, mixed>  $overrides
     */
    private function configureProduction(array $overrides = []): void
    {
        $this->ca = tempnam(sys_get_temp_dir(), 'preflight-ca-');
        file_put_contents($this->ca, "preflight test ca\n");

        config(array_replace([
            'app.env' => 'production',
            'app.debug' => false,
            'app.key' => 'base64:production-test-key-not-a-placeholder',
            'app.url' => 'https://app.example.com',
            'database.default' => 'mysql',
            'database.connections.mysql.database' => 'comercios_rd',
            'database.connections.mysql.unix_socket' => '',
            'database.connections.mysql.options' => [
                PDO::MYSQL_ATTR_SSL_CA => $this->ca,
            ],
            'ecf.enabled' => false,
            'security.two_factor.enabled' => true,
            'security.two_factor.required_roles' => ['owner', 'admin'],
            'security.two_factor.require_master' => true,
            'security.trusted_proxies' => '*',
            'security.session_fingerprint.enabled' => true,
            'session.driver' => 'redis',
            'session.secure' => true,
            'session.encrypt' => true,
            'cache.default' => 'redis',
            'queue.default' => 'redis',
            'scale.post_sale_queue' => true,
            'scale.target_concurrent_users' => 200,
            'portability.backup_enabled' => true,
            'portability.object_backup_verified' => false,
            'filesystems.default' => 'local',
            'filesystems.disks.private.driver' => 'local',
            'filesystems.disks.public.driver' => 'local',
            'filesystems.disks.public.bucket' => null,
            'filesystems.disks.private.bucket' => null,
        ], $overrides));
    }
}
