<?php

namespace Tests\Unit;

use App\Console\Commands\PortabilityBackup;
use App\Console\Commands\PortabilityRestore;
use App\Services\PortabilityRestoreOperations;
use App\Services\PortabilityRestoreTargetGuard;
use Illuminate\Config\Repository;
use Illuminate\Console\Command;
use Illuminate\Console\OutputStyle;
use Illuminate\Container\Container;
use Illuminate\Filesystem\Filesystem;
use Illuminate\Foundation\Application;
use Illuminate\Support\Facades\Facade;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Console\Input\ArrayInput;
use Symfony\Component\Console\Output\BufferedOutput;
use Symfony\Component\Process\Process;

class PortabilityWorkflowTest extends TestCase
{
    private string $directory;

    private string $originalPath;

    private Container $previousContainer;

    private $previousFacadeApplication;

    private Application $app;

    private BufferedOutput $output;

    protected function setUp(): void
    {
        parent::setUp();
        $this->previousContainer = Container::getInstance();
        $this->previousFacadeApplication = Facade::getFacadeApplication();
        $this->originalPath = (string) getenv('PATH');
        // Docker mounts /tmp with noexec; simulated clients must be executable.
        $this->directory = dirname(__DIR__, 2).'/storage/framework/cache/crd-portability-workflow-'.bin2hex(random_bytes(8));
        mkdir($this->directory.'/bin', 0700, true);
        $this->app = new Application($this->directory);
        $this->app->instance('config', new Repository([
            'app' => ['key' => 'unit-test-only', 'env' => 'testing'],
            'database' => ['default' => 'mysql', 'connections' => ['mysql' => [
                'database' => 'active_database', 'username' => 'unused', 'password' => '',
            ]]],
            'filesystems' => ['default' => 'local'],
        ]));
        $this->app->instance('files', new Filesystem);
        Facade::clearResolvedInstances();
        Facade::setFacadeApplication($this->app);
        $this->output = new BufferedOutput;
        putenv('PATH='.$this->directory.'/bin:'.$this->originalPath);
    }

    protected function tearDown(): void
    {
        putenv('PATH='.$this->originalPath);
        (new Filesystem)->deleteDirectory($this->directory);
        Facade::clearResolvedInstances();
        Facade::setFacadeApplication($this->previousFacadeApplication);
        Container::setInstance($this->previousContainer);
        parent::tearDown();
    }

    public function test_backup_publishes_only_a_complete_verified_backup(): void
    {
        $this->fakeDump(true);
        $command = $this->backupCommand();
        $this->assertSame(0, $command->handle(new PortabilityRestoreOperations));
        $directories = (new Filesystem)->directories($this->directory.'/backups');
        $this->assertCount(1, $directories);
        $this->assertStringNotContainsString('.incomplete', $directories[0]);
        (new PortabilityRestoreOperations)->verifyBackupIntegrity($directories[0]);
        $this->assertStringContainsString('Backup listo:', $this->output->fetch());
    }

    public function test_backup_accepts_the_mariadb_dump_client_used_by_the_testing_image(): void
    {
        $this->fakeDump(true, 0, true);
        $this->assertSame(0, $this->backupCommand()->handle(new PortabilityRestoreOperations));
        $this->assertStringContainsString('Backup listo:', $this->output->fetch());
    }

    #[DataProvider('backupFailures')]
    public function test_backup_does_not_publish_or_retain_incomplete_output(string $component, string $error): void
    {
        $this->fakeDump($component !== 'incomplete SQL', $component === 'mysqldump' ? 1 : 0);
        if (in_array($component, ['gzip', 'tar'], true)) {
            $this->binary($component, "exit 1\n");
        }
        $command = $this->backupCommand();
        $this->assertSame(1, $command->handle(new PortabilityRestoreOperations));
        $this->assertSame(['.', '..'], scandir($this->directory.'/backups'));
        $output = $this->output->fetch();
        $this->assertStringNotContainsString('Backup listo:', $output);
        $this->assertStringContainsString($error, $output);
    }

    public static function backupFailures(): array
    {
        return [
            ['mysqldump', 'mysqldump falló'],
            ['gzip', 'No se pudo comprimir el dump MySQL'],
            ['tar', 'tar falló'],
            ['incomplete SQL', 'Dump SQL incompleto'],
        ];
    }

    public function test_restore_accepts_valid_backup_and_publishes_files_in_a_new_destination(): void
    {
        $from = $this->makeBackup();
        $operations = $this->restoreOperations(0, 0);
        $operations->expects($this->never())->method('dropDatabase');
        $command = $this->restoreCommand($from);

        $this->assertSame(0, $command->handle($this->targetGuard(), $operations));
        $this->assertFileDoesNotExist($this->directory.'/restored/.restore-incomplete');
        $this->assertSame('public content', file_get_contents($this->directory.'/restored/public/document'));
        $this->assertSame('private content', file_get_contents($this->directory.'/restored/private/document'));
        $this->assertStringContainsString('Restore OK', $this->output->fetch());
    }

    public function test_restore_drops_only_its_new_database_after_import_failure(): void
    {
        $from = $this->makeBackup();
        $operations = $this->restoreOperations(0, 1);
        $operations->expects($this->once())->method('dropDatabase')->with('/unused/mysql', $this->anything(), 'disposable_copy');
        $command = $this->restoreCommand($from);

        $this->assertSame(1, $command->handle($this->targetGuard(), $operations));
        $this->assertDirectoryDoesNotExist($this->directory.'/restored');
        $output = $this->output->fetch();
        $this->assertStringContainsString('Restore SQL falló', $output);
        $this->assertStringNotContainsString('Restore OK', $output);
    }

    public function test_restore_reports_failed_database_cleanup_explicitly(): void
    {
        $from = $this->makeBackup();
        $operations = $this->restoreOperations(0, 1);
        $operations->expects($this->once())->method('dropDatabase')
            ->willThrowException(new \RuntimeException('DROP falló'));

        $this->assertSame(1, $this->restoreCommand($from)->handle($this->targetGuard(), $operations));
        $output = $this->output->fetch();
        $this->assertStringContainsString('DROP falló', $output);
        $this->assertStringContainsString('NO utilizarla', $output);
        $this->assertDirectoryDoesNotExist($this->directory.'/restored');
    }

    public function test_restore_never_imports_into_or_drops_a_preexisting_database(): void
    {
        $from = $this->makeBackup();
        $operations = $this->restoreOperations(1);
        $operations->expects($this->never())->method('dropDatabase');
        $command = $this->restoreCommand($from);

        $this->assertSame(1, $command->handle($this->targetGuard(), $operations));
        $this->assertDirectoryDoesNotExist($this->directory.'/restored');
        $this->assertStringContainsString('no se reutilizan bases existentes', $this->output->fetch());
    }

    public function test_restore_rejects_nonempty_database_before_preparing_files_or_creating_database(): void
    {
        $from = $this->makeBackup();
        $operations = $this->getMockBuilder(PortabilityRestoreOperations::class)
            ->onlyMethods(['mysqlBinary', 'ensureDatabaseIsEmpty', 'createDatabase', 'importDump', 'dropDatabase'])->getMock();
        $operations->method('mysqlBinary')->willReturn('/unused/mysql');
        $operations->expects($this->once())->method('ensureDatabaseIsEmpty')
            ->willThrowException(new \RuntimeException('la base destino no está vacía'));
        $operations->expects($this->never())->method('createDatabase');
        $operations->expects($this->never())->method('importDump');
        $operations->expects($this->never())->method('dropDatabase');

        $this->assertSame(1, $this->restoreCommand($from)->handle($this->targetGuard(), $operations));
        $this->assertDirectoryDoesNotExist($this->directory.'/restored');
    }

    public function test_restore_never_merges_with_an_existing_file_destination(): void
    {
        $from = $this->makeBackup();
        mkdir($this->directory.'/restored');
        file_put_contents($this->directory.'/restored/existing', 'preserve');
        $operations = $this->restoreOperations(null);

        $this->assertSame(1, $this->restoreCommand($from)->handle($this->targetGuard(), $operations));
        $this->assertSame('preserve', file_get_contents($this->directory.'/restored/existing'));
    }

    public function test_restore_checks_integrity_before_any_database_mutation(): void
    {
        $from = $this->makeBackup();
        file_put_contents($from.'/storage-private.tar.gz', 'corrupted');
        $operations = $this->getMockBuilder(PortabilityRestoreOperations::class)
            ->onlyMethods(['createDatabase', 'importDump', 'dropDatabase'])->getMock();
        foreach (['createDatabase', 'importDump', 'dropDatabase'] as $method) {
            $operations->expects($this->never())->method($method);
        }

        $this->assertSame(1, $this->restoreCommand($from)->handle($this->targetGuard(), $operations));
        $this->assertDirectoryDoesNotExist($this->directory.'/restored');
    }

    public function test_extraction_failure_removes_staging_without_creating_a_database(): void
    {
        $from = $this->makeBackup();
        $operations = $this->getMockBuilder(PortabilityRestoreOperations::class)
            ->onlyMethods(['mysqlBinary', 'ensureDatabaseIsEmpty', 'extractArchive', 'createDatabase', 'importDump'])->getMock();
        $operations->method('mysqlBinary')->willReturn('/unused/mysql');
        $operations->expects($this->once())->method('ensureDatabaseIsEmpty');
        $operations->expects($this->once())->method('extractArchive')
            ->willThrowException(new \RuntimeException('tar falló'));
        $operations->expects($this->never())->method('createDatabase');
        $operations->expects($this->never())->method('importDump');

        $this->assertSame(1, $this->restoreCommand($from)->handle($this->targetGuard(), $operations));
        $this->assertDirectoryDoesNotExist($this->directory.'/restored');
        $this->assertStringContainsString('tar falló', $this->output->fetch());
    }

    public function test_restore_cleans_up_if_import_throws_instead_of_returning_a_process(): void
    {
        $from = $this->makeBackup();
        $operations = $this->getMockBuilder(PortabilityRestoreOperations::class)
            ->onlyMethods(['mysqlBinary', 'ensureDatabaseIsEmpty', 'createDatabase', 'importDump', 'dropDatabase'])->getMock();
        $operations->method('mysqlBinary')->willReturn('/unused/mysql');
        $operations->expects($this->once())->method('ensureDatabaseIsEmpty');
        $operations->method('createDatabase')->willReturn(new Process([PHP_BINARY, '-r', 'exit(0);']));
        $operations->method('importDump')->willThrowException(new \RuntimeException('proceso interrumpido'));
        $operations->expects($this->once())->method('dropDatabase');

        $this->assertSame(1, $this->restoreCommand($from)->handle($this->targetGuard(), $operations));
        $this->assertDirectoryDoesNotExist($this->directory.'/restored');
    }

    public function test_restore_refuses_new_directories_inside_active_storage(): void
    {
        $from = $this->makeBackup();
        $operations = $this->restoreOperations(null);
        $command = $this->restoreCommand($from);
        $this->configureCommand($command, [
            '--from' => $from,
            '--database' => 'disposable_copy',
            '--storage-to' => $this->directory.'/storage/app/public/new',
        ]);

        $this->assertSame(1, $command->handle($this->targetGuard(), $operations));
        $this->assertDirectoryDoesNotExist($this->directory.'/storage/app/public/new');
        $this->assertSame('public content', file_get_contents($this->directory.'/storage/app/public/document'));
    }

    public function test_restore_preserves_active_database_guard_before_reading_backup(): void
    {
        $guard = $this->createMock(PortabilityRestoreTargetGuard::class);
        $guard->expects($this->once())->method('assertDestinationIsNotActive')
            ->willThrowException(new \RuntimeException('destino activo rechazado'));
        $operations = $this->createMock(PortabilityRestoreOperations::class);
        $operations->expects($this->never())->method('verifyBackupIntegrity');
        $operations->expects($this->never())->method('createDatabase');

        $this->assertSame(1, $this->restoreCommand('/not/read')->handle($guard, $operations));
        $this->assertStringContainsString('destino activo rechazado', $this->output->fetch());
    }

    private function backupCommand(): PortabilityBackup
    {
        $command = new class extends PortabilityBackup
        {
            protected function manifest(string $db, string $sqlGz): string
            {
                return "db_driver=mysql\n";
            }
        };
        $this->configureCommand($command, ['--dir' => $this->directory.'/backups']);

        return $command;
    }

    private function restoreCommand(string $from): PortabilityRestore
    {
        $command = new PortabilityRestore;
        $this->configureCommand($command, [
            '--from' => $from,
            '--database' => 'disposable_copy',
            '--storage-to' => $this->directory.'/restored',
        ]);

        return $command;
    }

    private function configureCommand(Command $command, array $options): void
    {
        $input = new ArrayInput($options, $command->getDefinition());
        $command->setLaravel($this->app);
        $command->setInput($input);
        $command->setOutput(new OutputStyle($input, $this->output));
    }

    private function restoreOperations(?int $createExit, int $importExit = 0): PortabilityRestoreOperations
    {
        $operations = $this->getMockBuilder(PortabilityRestoreOperations::class)
            ->onlyMethods(['mysqlBinary', 'ensureDatabaseIsEmpty', 'createDatabase', 'importDump', 'dropDatabase'])->getMock();
        $operations->method('mysqlBinary')->willReturn('/unused/mysql');
        $operations->expects($this->once())->method('ensureDatabaseIsEmpty');
        if ($createExit === null) {
            $operations->expects($this->never())->method('createDatabase');
        } else {
            $operations->expects($this->once())->method('createDatabase')
                ->willReturn(new Process([PHP_BINARY, '-r', 'exit('.$createExit.');']));
        }
        if ($createExit === 0) {
            $operations->expects($this->once())->method('importDump')
                ->willReturn(new Process([PHP_BINARY, '-r', 'exit('.$importExit.');']));
        } else {
            $operations->expects($this->never())->method('importDump');
        }

        return $operations;
    }

    private function targetGuard(): PortabilityRestoreTargetGuard
    {
        $guard = $this->createMock(PortabilityRestoreTargetGuard::class);
        $guard->expects($this->once())->method('assertDestinationIsNotActive');

        return $guard;
    }

    private function makeBackup(): string
    {
        $this->fakeDump(true);
        $result = $this->backupCommand()->handle(new PortabilityRestoreOperations);
        $this->assertSame(0, $result, $this->output->fetch());

        return (new Filesystem)->directories($this->directory.'/backups')[0];
    }

    private function fakeDump(bool $complete, int $exit = 0, bool $mariadb = false): void
    {
        foreach (['public', 'private'] as $disk) {
            mkdir($this->directory.'/storage/app/'.$disk, 0700, true);
            file_put_contents($this->directory.'/storage/app/'.$disk.'/document', $disk.' content');
        }
        $sql = "CREATE TABLE example (id INT);\n".($complete ? "-- Dump completed on 2026-01-01 00:00:00\n" : '');
        $version = $mariadb ? 'mysqldump Distrib 10.11-MariaDB' : 'mysqldump Ver 8.0';
        $this->binary('mysqldump', 'if [ "$2" = "--version" ]; then
    printf \'%s\' '.escapeshellarg($version).'
    exit 0
fi
for arg do
    '.($mariadb ? 'if [ "$arg" = "--set-gtid-purged=OFF" ]; then exit 1; fi' : '').'
    case "$arg" in --result-file=*) result="${arg#--result-file=}" ;; esac
done
printf \'%s\' '.escapeshellarg($sql).' > "$result"
exit '.$exit."\n");
    }

    private function binary(string $name, string $body): void
    {
        $path = $this->directory.'/bin/'.$name;
        file_put_contents($path, "#!/bin/sh\n".$body);
        chmod($path, 0700);
    }
}
