<?php

namespace Tests\Unit;

use App\Support\DatabaseConnectionGuard;
use App\Support\DatabaseTargetPolicy;
use App\Support\DatabaseTargetViolation;
use App\Support\ProcessEnvironment;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;

class DatabaseConnectionGuardTest extends TestCase
{
    private const PRODUCTION = [
        'driver' => 'mysql', 'host' => '10.0.0.5', 'port' => '3306', 'database' => 'comercios_rd',
        'username' => 'comercios_migrator', 'password' => 'x', 'unix_socket' => '',
    ];

    protected function tearDown(): void
    {
        ProcessEnvironment::fake(null);
        parent::tearDown();
    }

    #[DataProvider('statements')]
    public function test_schema_statements_are_classified(string $sql, bool $schema): void
    {
        $this->assertSame($schema, DatabaseConnectionGuard::isSchemaStatement($sql));
    }

    public static function statements(): array
    {
        return [
            'create table' => ['create table `x` (`id` int)', true],
            'alter' => ['  ALTER TABLE sales ADD COLUMN y INT', true],
            'drop' => ['drop table if exists `x`', true],
            'create database' => ['CREATE DATABASE IF NOT EXISTS `comercios_rd`', true],
            'rename' => ['rename table a to b', true],
            'grant' => ['GRANT ALL ON *.* TO x', true],
            'behind comment' => ["/* x */ -- y\nDROP TABLE x", true],
            'second statement' => ['select 1; drop table users', true],
            'select' => ['select * from `sales` where `id` = ?', false],
            'insert' => ['insert into `drops` (`create`) values (?)', false],
            'temporary table' => ['create temporary table t (id int)', false],
            'drop temporary' => ['DROP TEMPORARY TABLE IF EXISTS t', false],
            'truncate' => ['truncate table `login_attempts`', false],
            'isolation' => ['SET TRANSACTION ISOLATION LEVEL READ COMMITTED', false],
        ];
    }

    public function test_outside_testing_schema_changes_require_the_exact_process_approval(): void
    {
        ProcessEnvironment::fake([]);
        $this->assertDenied(fn () => DatabaseConnectionGuard::authorizeStatement('ALTER TABLE x', self::PRODUCTION, 'mysql', false));

        ProcessEnvironment::fake([DatabaseTargetPolicy::APPROVAL_VARIABLE => 'comercios_rd@otro']);
        $this->assertDenied(fn () => DatabaseConnectionGuard::authorizeStatement('ALTER TABLE x', self::PRODUCTION, 'mysql', false));

        ProcessEnvironment::fake([DatabaseTargetPolicy::APPROVAL_VARIABLE => 'comercios_rd@10.0.0.5']);
        DatabaseConnectionGuard::authorizeStatement('ALTER TABLE x', self::PRODUCTION, 'mysql', false);

        ProcessEnvironment::fake([]);
        DatabaseConnectionGuard::authorizeStatement('select 1', self::PRODUCTION, 'mysql', false);
        DatabaseConnectionGuard::authorizeConnect(self::PRODUCTION, false);
        $this->addToAssertionCount(2);
    }

    public function test_in_testing_connections_and_schema_changes_require_the_isolated_target(): void
    {
        $this->assertDenied(fn () => DatabaseConnectionGuard::authorizeConnect(self::PRODUCTION, true));
        $this->assertDenied(fn () => DatabaseConnectionGuard::authorizeStatement('DROP TABLE x', self::PRODUCTION, 'mysql', true));
        $this->assertDenied(fn () => DatabaseConnectionGuard::authorizeConnect(['driver' => 'sqlite', 'database' => ':memory:'], true));
    }

    /**
     * Command::call(SomeCommand::class) bypasses the Artisan gate; only name-based calls are allowed.
     */
    public function test_application_code_does_not_call_commands_by_class(): void
    {
        $root = dirname(__DIR__, 2);
        $offenders = [];
        foreach (['app', 'routes', 'database'] as $directory) {
            $files = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root.'/'.$directory));
            foreach ($files as $file) {
                if ($file->isFile() && $file->getExtension() === 'php'
                    && preg_match('/\b(?:call|callSilent|callSilently)\(\s*\\\\?[A-Z][\w\\\\]*::class/', (string) file_get_contents($file->getPathname())) === 1) {
                    $offenders[] = substr($file->getPathname(), strlen($root) + 1);
                }
            }
        }

        $this->assertSame([], $offenders);
    }

    private function assertDenied(callable $callback): void
    {
        try {
            $callback();
            $this->fail('Permitido indebidamente.');
        } catch (DatabaseTargetViolation) {
            $this->addToAssertionCount(1);
        }
    }
}
