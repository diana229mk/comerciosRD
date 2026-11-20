<?php

namespace Tests\Feature;

use App\Console\ArtisanCommandGate;
use App\Support\DatabaseTargetPolicy;
use App\Support\DatabaseTargetViolation;
use App\Support\ProcessEnvironment;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class ArtisanDatabaseGuardTest extends TestCase
{
    protected function tearDown(): void
    {
        ProcessEnvironment::fake(null);
        ArtisanCommandGate::useConfirmation(null);
        parent::tearDown();
    }

    /**
     * Commands are rejected before execution, so the rogue connection is never created
     * and no statement reaches any server.
     */
    #[DataProvider('dangerousCommands')]
    public function test_dangerous_commands_against_another_connection_are_blocked_before_connecting(
        string $command,
        array $parameters,
    ): void {
        config(['database.connections.rogue' => [
            'driver' => 'mysql', 'url' => '', 'host' => '127.0.0.1', 'port' => '3306',
            'database' => 'comercios_rd', 'username' => 'comercios_user', 'password' => 'x', 'unix_socket' => '',
        ]]);
        $statements = 0;
        DB::listen(function (QueryExecuted $query) use (&$statements): void {
            $statements++;
        });

        try {
            Artisan::call($command, $parameters + ['--database' => 'rogue']);
            $this->fail("{$command} no fue bloqueado.");
        } catch (DatabaseTargetViolation $exception) {
            $this->assertStringContainsString('rogue', $exception->getMessage());
        }

        $this->assertArrayNotHasKey('rogue', DB::getConnections());
        $this->assertSame(0, $statements);
    }

    public static function dangerousCommands(): array
    {
        return [
            'migrate' => ['migrate', ['--force' => true]],
            'migrate seed' => ['migrate', ['--seed' => true, '--force' => true]],
            'migrate:fresh' => ['migrate:fresh', ['--force' => true]],
            'migrate:refresh' => ['migrate:refresh', ['--force' => true]],
            'migrate:rollback' => ['migrate:rollback', ['--force' => true]],
            'migrate:reset' => ['migrate:reset', ['--force' => true]],
            'db:wipe' => ['db:wipe', ['--force' => true]],
            'db:seed' => ['db:seed', ['--force' => true]],
            'schema:dump' => ['schema:dump', []],
        ];
    }

    public function test_cli_abbreviations_are_resolved_before_the_guard_decides(): void
    {
        config(['database.connections.rogue' => [
            'driver' => 'mysql', 'url' => '', 'host' => '127.0.0.1', 'port' => '3306',
            'database' => 'comercios_rd', 'username' => 'comercios_user', 'password' => 'x', 'unix_socket' => '',
        ]]);
        $output = new \Symfony\Component\Console\Output\BufferedOutput;

        $status = $this->app[\Illuminate\Contracts\Console\Kernel::class]->handle(
            new \Symfony\Component\Console\Input\ArgvInput(['artisan', 'migrate:fre', '--database=rogue', '--force']),
            $output,
        );

        $this->assertSame(1, $status);
        $this->assertStringContainsString('rogue', $output->fetch());
        $this->assertArrayNotHasKey('rogue', DB::getConnections());
    }

    public function test_alternative_sqlite_connection_is_rejected(): void
    {
        $this->expectException(DatabaseTargetViolation::class);

        Artisan::call('migrate', ['--database' => 'sqlite', '--force' => true]);
    }

    public function test_runtime_reconfiguration_of_the_default_connection_is_rejected_before_migrate(): void
    {
        config(['database.connections.mysql.database' => 'comercios_rd']);

        try {
            $this->expectException(DatabaseTargetViolation::class);
            Artisan::call('migrate', ['--force' => true]);
        } finally {
            config(['database.connections.mysql.database' => DatabaseTargetPolicy::TEST_DATABASE]);
        }
    }

    public function test_read_only_commands_keep_working_on_the_isolated_database(): void
    {
        // The guard must not reject a legitimate read; the exit code only reflects the schema state.
        Artisan::call('migrate:status');

        $this->assertStringNotContainsString('rechazada', Artisan::output());
        $this->assertStringNotContainsString('bloqueado', Artisan::output());
    }

    public function test_testing_outside_the_authorized_runtime_only_allows_database_free_commands(): void
    {
        ProcessEnvironment::fake([]);

        $this->assertSame(0, Artisan::call('list'));

        $this->expectException(DatabaseTargetViolation::class);
        Artisan::call('migrate:status');
    }

    public function test_non_testing_environments_require_exact_process_approval_and_tty_for_protected_databases(): void
    {
        $original = $this->app['env'];
        config(['database.connections.real' => [
            'driver' => 'mysql', 'url' => '', 'host' => '127.0.0.1', 'port' => '3306',
            'database' => 'comercios_rd', 'username' => 'comercios_user', 'password' => 'x', 'unix_socket' => '',
        ]]);
        $command = $this->app->make(\Illuminate\Database\Console\Migrations\MigrateCommand::class);
        $command->setLaravel($this->app);
        $input = new \Symfony\Component\Console\Input\ArrayInput(['--database' => 'real'], $command->getDefinition());

        try {
            $this->app['env'] = 'local';
            ProcessEnvironment::fake([]);
            $this->assertBlocked($command, $input, 'sin aprobación');

            ProcessEnvironment::fake([DatabaseTargetPolicy::APPROVAL_VARIABLE => 'comercios_rd@otro-host']);
            $this->assertBlocked($command, $input, 'aprobación de otro destino');

            ProcessEnvironment::fake([DatabaseTargetPolicy::APPROVAL_VARIABLE => 'comercios_rd@127.0.0.1']);
            ArtisanCommandGate::useConfirmation(fn () => null);
            $this->assertBlocked($command, $input, 'sin TTY');

            ArtisanCommandGate::useConfirmation(fn () => "comercios_rd\n");
            ArtisanCommandGate::authorize($this->app, $command, $input, ArtisanCommandGate::SCHEMA);

            $this->app['env'] = 'production';
            ArtisanCommandGate::authorize($this->app, $command, $input, ArtisanCommandGate::SCHEMA);
            $this->assertBlocked($command, $input, 'destructivo en producción', ArtisanCommandGate::DESTRUCTIVE);
        } finally {
            $this->app['env'] = $original;
        }
    }

    private function assertBlocked(object $command, object $input, string $case, int $level = ArtisanCommandGate::SCHEMA): void
    {
        try {
            ArtisanCommandGate::authorize($this->app, $command, $input, $level);
            $this->fail("Permitido indebidamente: {$case}.");
        } catch (DatabaseTargetViolation) {
            $this->addToAssertionCount(1);
        }
    }
}
