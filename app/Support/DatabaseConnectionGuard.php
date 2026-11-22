<?php

namespace App\Support;

use Illuminate\Contracts\Foundation\Application;
use Illuminate\Database\Connection;
use Illuminate\Database\Events\ConnectionEstablished;
use WeakMap;

/**
 * Connection-level revalidation (guard measure 3). Complements ArtisanCommandGate
 * for paths that never pass through an Artisan command: runtime config changes,
 * direct use of the Migrator or a connection, and nested command calls made by class name.
 *
 * - Every PDO a connector opens is checked against the effective config it receives.
 * - Every schema statement (DDL) is checked against the connection that executes it.
 */
final class DatabaseConnectionGuard
{
    /**
     * Leading keywords treated as schema/privilege changes. TRUNCATE is data-level
     * (login:unlock --all uses it in production) and temporary tables are session-local.
     */
    private const SCHEMA_STATEMENT = '/(?:^|;)\s*(?:(?:\/\*.*?\*\/|--[^\n]*\n|#[^\n]*\n)\s*)*'
        .'(?!(?:CREATE|DROP)\s+TEMPORARY\b)(CREATE|ALTER|DROP|RENAME|GRANT|REVOKE)\b/is';

    /** @var WeakMap<Connection, true>|null */
    private static ?WeakMap $guarded = null;

    public static function register(Application $app): void
    {
        $app['events']->listen(
            ConnectionEstablished::class,
            static fn (ConnectionEstablished $event) => self::attach($event->connection),
        );

        if ($app->resolved('db')) {
            foreach ($app['db']->getConnections() as $connection) {
                self::attach($connection);
            }
        }
    }

    public static function attach(Connection $connection): void
    {
        self::$guarded ??= new WeakMap;
        if (isset(self::$guarded[$connection])) {
            return;
        }
        self::$guarded[$connection] = true;

        $connection->beforeExecuting(
            static fn (string $query, array $bindings, Connection $connection) => self::authorizeStatement(
                $query,
                $connection->getConfig(),
                (string) $connection->getName(),
            ),
        );
    }

    /**
     * Called by every connector before a PDO is created.
     *
     * @throws DatabaseTargetViolation
     */
    public static function authorizeConnect(array $config, ?bool $testing = null): void
    {
        if (! ($testing ?? DatabaseTargetPolicy::isTestingProcess())) {
            return;
        }

        $name = is_string($config['name'] ?? null) ? $config['name'] : 'mysql';
        DatabaseTargetPolicy::assertTestingConnection($config, $name);
    }

    /**
     * @throws DatabaseTargetViolation
     */
    public static function authorizeStatement(string $query, array $config, string $name, ?bool $testing = null): void
    {
        if (! self::isSchemaStatement($query)) {
            return;
        }

        if ($testing ?? DatabaseTargetPolicy::isTestingProcess()) {
            DatabaseTargetPolicy::assertTestingConnection($config, $name);

            return;
        }

        $label = DatabaseTargetPolicy::approvalLabel($config);
        $approval = trim((string) ProcessEnvironment::get(DatabaseTargetPolicy::APPROVAL_VARIABLE));

        if (! hash_equals($label, $approval)) {
            throw new DatabaseTargetViolation(
                "Cambio de esquema bloqueado sobre la conexión '{$name}' ({$label}). Exporte "
                .DatabaseTargetPolicy::APPROVAL_VARIABLE.'=<base>@<host> del destino en el entorno del proceso.'
            );
        }
    }

    public static function isSchemaStatement(string $query): bool
    {
        return preg_match(self::SCHEMA_STATEMENT, $query) === 1;
    }
}
