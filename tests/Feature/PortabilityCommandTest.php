<?php

namespace Tests\Feature;

use App\Contracts\MysqlServerIdentityReader;
use App\Services\PortabilityRestoreOperations;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class PortabilityCommandTest extends TestCase
{
    use DatabaseTransactions;

    public function test_check_runs(): void
    {
        $this->artisan('portability:check')->assertSuccessful();
    }

    public function test_restore_refuses_the_live_database(): void
    {
        $this->assertRejectedActiveRestore(true);
    }

    public function test_restore_onto_empty_server_refuses_when_active_database_has_no_businesses(): void
    {
        $this->assertRejectedActiveRestore(true);
    }

    public function test_restore_refuses_same_server_and_schema_through_a_different_connection_endpoint(): void
    {
        $this->stubIdentities('active-server-uuid');
        $operations = $this->mock(PortabilityRestoreOperations::class);
        $this->expectNoRestoreOperations($operations);
        $before = $this->applicationRowCounts();

        $this->artisan('portability:restore', [
            '--from' => '/not/read/at/all',
            '--database' => config('database.connections.mysql.database'),
            '--target-host' => 'localhost',
            '--onto-empty-server' => true,
        ])->expectsOutputToContain('no fue posible demostrar')->assertFailed();
        $this->assertSame($before, $this->applicationRowCounts());
    }

    public function test_restore_refuses_same_configured_endpoint_and_schema_even_if_server_id_is_ambiguous(): void
    {
        $reader = $this->mock(MysqlServerIdentityReader::class);
        $reader->shouldReceive('active')->once()->andReturn($this->activeIdentity());
        $reader->shouldReceive('target')->once()->andReturn([
            'server_uuid' => 'different-or-replaced-server-id',
            'server_port' => '3306',
            'server_socket' => '/run/mysqld/mysqld.sock',
        ]);

        $operations = $this->mock(PortabilityRestoreOperations::class);
        $this->expectNoRestoreOperations($operations);
        $before = $this->applicationRowCounts();

        $this->artisan('portability:restore', [
            '--from' => '/not/read/at/all',
            '--database' => config('database.connections.mysql.database'),
            '--onto-empty-server' => true,
        ])->expectsOutputToContain('no fue posible demostrar')->assertFailed();

        $this->assertSame($before, $this->applicationRowCounts());
    }

    public function test_restore_fails_closed_when_target_identity_cannot_be_verified(): void
    {
        $reader = $this->mock(MysqlServerIdentityReader::class);
        $reader->shouldReceive('active')->once()->andReturn($this->activeIdentity());
        $reader->shouldReceive('target')->once()->andThrow(new \RuntimeException('connection failure'));

        $operations = $this->mock(PortabilityRestoreOperations::class);
        $this->expectNoRestoreOperations($operations);
        $before = $this->applicationRowCounts();

        $this->artisan('portability:restore', [
            '--from' => '/not/read/at/all',
            '--database' => 'comercios_copy',
        ])->expectsOutputToContain('no fue posible demostrar')->assertFailed();

        $this->assertSame($before, $this->applicationRowCounts());
    }

    public function test_distinct_authorized_destination_passes_identity_check_before_restore_preparation(): void
    {
        $this->stubIdentities('same-mysql-server');
        $operations = $this->mock(PortabilityRestoreOperations::class);
        $operations->shouldReceive('mysqlBinary')->once()->andReturn('');
        $operations->shouldNotReceive('createDatabase');
        $operations->shouldNotReceive('importDump');
        $operations->shouldNotReceive('extractArchive');
        $directory = $this->backupDirectory();

        try {
            $this->artisan('portability:restore', [
                '--from' => $directory,
                '--database' => 'comercios_rd_copy',
            ])->expectsOutputToContain('cliente mysql no está instalado')->assertFailed();
        } finally {
            @unlink($directory.'/database.sql.gz');
            @rmdir($directory);
        }
    }

    public function test_restore_refuses_name_without_copy_suffix(): void
    {
        $dir = sys_get_temp_dir().'/crd-portability-refuse-'.uniqid();
        mkdir($dir, 0700, true);
        file_put_contents($dir.'/database.sql.gz', gzencode("-- fake\n"));

        try {
            $this->artisan('portability:restore', [
                '--from' => $dir,
                '--database' => 'otra_base_en_serio',
            ])->assertFailed();
        } finally {
            @unlink($dir.'/database.sql.gz');
            @rmdir($dir);
        }
    }

    private function assertRejectedActiveRestore(bool $ontoEmpty): void
    {
        $this->assertSame(0, DB::table('businesses')->count());
        $this->stubIdentities('active-server-uuid');

        $operations = $this->mock(PortabilityRestoreOperations::class);
        $this->expectNoRestoreOperations($operations);
        $before = $this->applicationRowCounts();
        $options = [
            '--from' => '/not/read/at/all',
            '--database' => config('database.connections.mysql.database'),
        ];
        if ($ontoEmpty) {
            $options['--onto-empty-server'] = true;
        }

        $this->artisan('portability:restore', $options)
            ->expectsOutputToContain('no fue posible demostrar')
            ->assertFailed();

        $this->assertSame($before, $this->applicationRowCounts());
    }

    private function stubIdentities(string $targetServerUuid): void
    {
        $reader = $this->mock(MysqlServerIdentityReader::class);
        $reader->shouldReceive('active')->once()->andReturn($this->activeIdentity());
        $reader->shouldReceive('target')->once()->andReturn([
            'server_uuid' => $targetServerUuid,
            'server_port' => '3306',
            'server_socket' => '/run/mysqld/mysqld.sock',
        ]);
    }

    /**
     * @return array{database:string,server_uuid:string,server_port:string,server_socket:string}
     */
    private function activeIdentity(): array
    {
        return [
            'database' => (string) config('database.connections.mysql.database'),
            'server_uuid' => 'active-server-uuid',
            'server_port' => '3306',
            'server_socket' => '/run/mysqld/mysqld.sock',
        ];
    }

    /**
     * @return array{businesses:int,users:int,migrations:int}
     */
    private function applicationRowCounts(): array
    {
        return [
            'businesses' => DB::table('businesses')->count(),
            'users' => DB::table('users')->count(),
            'migrations' => DB::table('migrations')->count(),
        ];
    }

    private function backupDirectory(): string
    {
        $directory = sys_get_temp_dir().'/crd-portability-test-'.uniqid();
        mkdir($directory, 0700, true);
        file_put_contents($directory.'/database.sql.gz', gzencode("-- fake\n"));

        return $directory;
    }

    private function expectNoRestoreOperations(PortabilityRestoreOperations $operations): void
    {
        $operations->shouldNotReceive('mysqlBinary');
        $operations->shouldNotReceive('createDatabase');
        $operations->shouldNotReceive('importDump');
        $operations->shouldNotReceive('extractArchive');
    }
}
