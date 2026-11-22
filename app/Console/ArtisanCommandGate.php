<?php

namespace App\Console;

use App\Console\Commands\PortabilityRestore;
use App\Support\DatabaseTargetPolicy;
use App\Support\DatabaseTargetViolation;
use App\Support\ProcessEnvironment;
use App\Support\TestRuntime;
use Illuminate\Contracts\Foundation\Application;
use Illuminate\Database\Console\DbCommand;
use Illuminate\Database\Console\DumpCommand;
use Illuminate\Database\Console\Migrations\FreshCommand;
use Illuminate\Database\Console\Migrations\InstallCommand;
use Illuminate\Database\Console\Migrations\MigrateCommand;
use Illuminate\Database\Console\Migrations\RefreshCommand;
use Illuminate\Database\Console\Migrations\ResetCommand;
use Illuminate\Database\Console\Migrations\RollbackCommand;
use Illuminate\Database\Console\Migrations\StatusCommand;
use Illuminate\Database\Console\MonitorCommand;
use Illuminate\Database\Console\Seeds\SeedCommand;
use Illuminate\Database\Console\ShowCommand;
use Illuminate\Database\Console\TableCommand;
use Illuminate\Database\Console\WipeCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Throwable;

/**
 * Decides whether an already-resolved Artisan command may run against its
 * effective database target. Classification uses the command class, not the
 * typed name, so abbreviations, aliases and Artisan::call() are covered.
 */
final class ArtisanCommandGate
{
    public const OTHER = 0;

    public const READ = 1;

    public const CLIENT = 2;

    public const SCHEMA = 3;

    public const DATA = 4;

    public const DESTRUCTIVE = 5;

    /** Commands allowed with APP_ENV=testing outside the authorized test container. */
    public const DATABASE_FREE_COMMANDS = [
        'list', 'help', 'completion', '_complete',
        'package:discover', 'config:clear', 'view:clear', 'filament:upgrade',
    ];

    private const DESTRUCTIVE_CLASSES = [
        FreshCommand::class, RefreshCommand::class, ResetCommand::class, RollbackCommand::class, WipeCommand::class,
    ];

    private const DATA_CLASSES = [SeedCommand::class, PortabilityRestore::class];

    private const SCHEMA_CLASSES = [MigrateCommand::class, InstallCommand::class, DumpCommand::class];

    private const READ_CLASSES = [StatusCommand::class, ShowCommand::class, TableCommand::class, MonitorCommand::class];

    /** Gate classes whose --database option names a Laravel connection. */
    private const CONNECTION_OPTION_CLASSES = [
        ...self::DESTRUCTIVE_CLASSES, SeedCommand::class, ...self::SCHEMA_CLASSES,
        ...self::READ_CLASSES, DbCommand::class,
    ];

    /** @var callable(string):?string|null */
    private static $confirmation = null;

    public static function classify(Command $command, ?InputInterface $input): int
    {
        $level = match (true) {
            self::isA($command, self::DESTRUCTIVE_CLASSES) => self::DESTRUCTIVE,
            self::isA($command, self::DATA_CLASSES) => self::DATA,
            self::isA($command, self::SCHEMA_CLASSES) => self::SCHEMA,
            $command instanceof DbCommand => self::CLIENT,
            self::isA($command, self::READ_CLASSES) => self::READ,
            default => self::classifyByName((string) $command->getName()),
        };

        if ($input !== null && $level === self::SCHEMA
            && (self::option($input, 'seed') || self::option($input, 'seeder'))) {
            $level = self::DATA;
        }

        return $level;
    }

    /**
     * @throws DatabaseTargetViolation
     */
    public static function authorize(Application $app, Command $command, ?InputInterface $input, int $level): void
    {
        $name = (string) $command->getName();

        if ($app->environment('testing')) {
            self::authorizeTesting($app, $command, $input, $name, $level);

            return;
        }

        if ($level < self::CLIENT) {
            return;
        }

        [$connection, $config] = self::target($command, $input);
        $label = DatabaseTargetPolicy::approvalLabel($config);
        $approval = (string) ProcessEnvironment::get(DatabaseTargetPolicy::APPROVAL_VARIABLE);

        if (! hash_equals($label, trim($approval))) {
            throw new DatabaseTargetViolation(
                "Comando '{$name}' bloqueado sobre la conexión '{$connection}'. "
                .'Exporte '.DatabaseTargetPolicy::APPROVAL_VARIABLE.'=<base>@<host> del destino en el '
                .'entorno del proceso para esta ejecución (no se acepta desde .env).'
            );
        }

        if ($level === self::DESTRUCTIVE && $app->environment('production')) {
            throw new DatabaseTargetViolation(
                "Comando destructivo '{$name}' bloqueado en producción. Use el procedimiento de recuperación."
            );
        }

        if ($app->environment('local') && DatabaseTargetPolicy::isProtectedDatabase($config)) {
            $database = explode('@', $label, 2)[0];
            $typed = self::confirm(
                "Comando '{$name}' sobre la base protegida {$label}. Escriba el nombre de la base para confirmar: "
            );
            if ($typed === null || ! hash_equals($database, trim($typed))) {
                throw new DatabaseTargetViolation(
                    "Comando '{$name}' bloqueado: la base protegida requiere confirmación interactiva en una TTY."
                );
            }
        }
    }

    /**
     * @internal Test seam for the interactive confirmation.
     *
     * @param  callable(string):?string|null  $confirmation
     */
    public static function useConfirmation(?callable $confirmation): void
    {
        self::$confirmation = $confirmation;
    }

    private static function authorizeTesting(
        Application $app,
        Command $command,
        ?InputInterface $input,
        string $name,
        int $level,
    ): void {
        if (! TestRuntime::isAuthorized()) {
            if (in_array($name, self::DATABASE_FREE_COMMANDS, true)) {
                return;
            }

            TestRuntime::assertAuthorized();
        }

        if ($level < self::READ) {
            return;
        }

        DatabaseTargetPolicy::assertConfigurationNotCached($app);
        [$connection, $config] = self::target($command, $input);
        DatabaseTargetPolicy::assertTestingConnection($config, $connection);
    }

    /**
     * @return array{0:string,1:array<string,mixed>}
     */
    private static function target(Command $command, ?InputInterface $input): array
    {
        $connection = (string) config('database.default');

        if ($input !== null && self::isA($command, self::CONNECTION_OPTION_CLASSES)) {
            $requested = self::optionValue($input, 'database');
            if (is_string($requested) && trim($requested) !== '') {
                $connection = trim($requested);
            }
        }

        $config = config("database.connections.{$connection}");
        if ($connection === '' || ! is_array($config)) {
            throw new DatabaseTargetViolation("La conexión '{$connection}' no existe; destino indeterminado.");
        }

        return [$connection, $config];
    }

    private static function classifyByName(string $name): int
    {
        if ($name === 'migrate:status' || $name === 'db:show' || $name === 'db:table' || $name === 'db:monitor') {
            return self::READ;
        }

        // Unknown commands in database namespaces are treated as schema changes (fail closed).
        if (str_starts_with($name, 'migrate') || str_starts_with($name, 'db:') || str_starts_with($name, 'schema:')
            || $name === 'db') {
            return self::SCHEMA;
        }

        return self::OTHER;
    }

    /** @param list<class-string> $classes */
    private static function isA(Command $command, array $classes): bool
    {
        foreach ($classes as $class) {
            if ($command instanceof $class) {
                return true;
            }
        }

        return false;
    }

    private static function option(InputInterface $input, string $name): bool
    {
        $value = self::optionValue($input, $name);

        return $value !== null && $value !== false && $value !== '';
    }

    private static function optionValue(InputInterface $input, string $name): mixed
    {
        try {
            return $input->hasOption($name) ? $input->getOption($name) : null;
        } catch (Throwable) {
            return null;
        }
    }

    private static function confirm(string $prompt): ?string
    {
        if (self::$confirmation !== null) {
            return (self::$confirmation)($prompt);
        }

        if (! defined('STDIN') || ! is_resource(STDIN) || ! stream_isatty(STDIN)) {
            return null;
        }

        fwrite(STDERR, $prompt);
        $line = fgets(STDIN);

        return $line === false ? null : $line;
    }
}
