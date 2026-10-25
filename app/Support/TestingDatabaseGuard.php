<?php

namespace App\Support;

use Illuminate\Contracts\Foundation\Application;
use Illuminate\Support\Facades\DB;
use RuntimeException;

class TestingDatabaseGuard
{
    private const DATABASE = 'comercios_rd_storage_test';

    private const DEVELOPMENT_DATABASE = 'comercios_rd';

    private const REQUIRED_PRIVILEGES = [
        'SELECT',
        'INSERT',
        'UPDATE',
        'DELETE',
        'CREATE',
        'ALTER',
        'INDEX',
        'DROP',
        'REFERENCES',
    ];

    private const ALLOWED_PRIVILEGES = [
        ...self::REQUIRED_PRIVILEGES,
        'CREATE TEMPORARY TABLES',
        'LOCK TABLES',
    ];

    public static function assertIsolated(Application $app): void
    {
        $username = (string) config('database.connections.mysql.username');
        $password = (string) config('database.connections.mysql.password');

        if ($app->environment() !== 'testing'
            || config('database.default') !== 'mysql'
            || config('database.connections.mysql.database') !== self::DATABASE
            || filled(config('database.connections.mysql.url'))
            || blank($username)
            || blank($password)
            || preg_match('/^\$\{[^}]+\}$/', $username) === 1
            || preg_match('/^\$\{[^}]+\}$/', $password) === 1) {
            throw new RuntimeException(
                'Testing requires a dedicated MySQL connection to comercios_rd_storage_test; refusing to run.'
            );
        }

        try {
            $connection = DB::connection('mysql');
            $identity = $connection->selectOne(
                'SELECT DATABASE() AS database_name, @@hostname AS server, CURRENT_USER() AS account'
            );

            if ($identity?->database_name !== self::DATABASE) {
                throw new RuntimeException;
            }

            $grants = $connection->select('SHOW GRANTS');
            $privileges = [];

            foreach ($grants as $grantRow) {
                $grant = trim((string) array_values((array) $grantRow)[0]);

                if (preg_match('/^GRANT\s+USAGE\s+ON\s+\*\.\*/i', $grant) === 1) {
                    continue;
                }

                if (str_contains(strtoupper($grant), 'WITH GRANT OPTION')
                    || preg_match(
                        '/^GRANT\s+(.+?)\s+ON\s+`?'.preg_quote(self::DATABASE, '/').'`?\.\*\s+TO\b/i',
                        $grant,
                        $matches,
                    ) !== 1) {
                    throw new RuntimeException;
                }

                foreach (explode(',', strtoupper($matches[1])) as $privilege) {
                    $privilege = trim($privilege);
                    if (! in_array($privilege, self::ALLOWED_PRIVILEGES, true)) {
                        throw new RuntimeException;
                    }
                    $privileges[] = $privilege;
                }
            }

            if (array_diff(self::REQUIRED_PRIVILEGES, array_unique($privileges)) !== []) {
                throw new RuntimeException;
            }
        } catch (\Throwable) {
            throw new RuntimeException(
                'Could not verify isolated test database identity and grants; refusing to run.'
            );
        }
    }

    public static function assertMigrationTargetIsSafe(Application $app): void
    {
        $database = config('database.connections.'.config('database.default').'.database');

        if ($database === self::DEVELOPMENT_DATABASE) {
            throw new RuntimeException(
                'Migration refused: comercios_rd is protected from destructive migration commands.'
            );
        }

        if ($app->environment('testing')) {
            self::assertIsolated($app);
        }
    }
}
