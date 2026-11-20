<?php

namespace App\Support;

use Illuminate\Contracts\Foundation\Application;
use Illuminate\Support\ConfigurationUrlParser;
use Throwable;

/**
 * Central, connection-free policy that decides whether a database target may
 * be used. It never opens a connection and denies whenever the effective
 * target cannot be determined unambiguously.
 */
final class DatabaseTargetPolicy
{
    public const TEST_DATABASE = 'comercios_rd_storage_test';

    /** Hostnames that only resolve inside the internal Docker test networks. */
    public const TEST_HOSTS = ['test-mysql', 'test-mysql-tls', 'wrong-mysql-tls', 'test-mysql-no-tls'];

    public const TEST_PORT = '3306';

    public const TEST_USERS = ['comercios_storage_test', 'comercios_data02_test'];

    public const PROTECTED_DATABASES = ['comercios_rd'];

    /** @@server_uuid of the host MySQL that serves comercios_rd. */
    public const PROTECTED_SERVER_UUIDS = ['81f5bbf6-224a-11f1-ad19-d8808330071f'];

    public const APPROVAL_VARIABLE = 'COMERCIOS_SCHEMA_CHANGE_APPROVAL';

    /**
     * Resolves every endpoint the connection could use (url, read/write splits).
     *
     * @return array{driver:string,hosts:list<string>,ports:list<string>,databases:list<string>,usernames:list<string>,sockets:list<string>}
     */
    public static function resolve(array $config): array
    {
        try {
            $effective = (new ConfigurationUrlParser)->parseConfiguration($config);
        } catch (Throwable) {
            throw new DatabaseTargetViolation('La URL de conexión no se pudo interpretar.');
        }

        $base = $effective;
        unset($base['read'], $base['write']);
        $layers = [$base];

        foreach (['read', 'write'] as $type) {
            if (! array_key_exists($type, $effective)) {
                continue;
            }
            if (! is_array($effective[$type])) {
                throw new DatabaseTargetViolation("La sección {$type} de la conexión es ambigua.");
            }
            $variants = array_is_list($effective[$type]) && $effective[$type] !== []
                ? $effective[$type]
                : [$effective[$type]];
            foreach ($variants as $variant) {
                if (! is_array($variant)) {
                    throw new DatabaseTargetViolation("La sección {$type} de la conexión es ambigua.");
                }
                $layers[] = array_merge($base, $variant);
            }
        }

        $resolved = ['driver' => self::scalar($base['driver'] ?? null), 'hosts' => [], 'ports' => [],
            'databases' => [], 'usernames' => [], 'sockets' => []];

        foreach ($layers as $layer) {
            foreach ((array) ($layer['host'] ?? '') as $host) {
                $resolved['hosts'][] = strtolower(self::scalar($host));
            }
            $resolved['ports'][] = self::scalar($layer['port'] ?? '');
            $resolved['databases'][] = self::scalar($layer['database'] ?? '');
            $resolved['usernames'][] = self::scalar($layer['username'] ?? '');
            $resolved['sockets'][] = self::scalar($layer['unix_socket'] ?? ($layer['socket'] ?? ''));
        }

        foreach (['hosts', 'ports', 'databases', 'usernames', 'sockets'] as $key) {
            $resolved[$key] = array_values(array_unique($resolved[$key]));
        }

        return $resolved;
    }

    /**
     * Strict contract for any connection used while APP_ENV=testing.
     */
    public static function assertTestingConnection(array $config, string $name = 'mysql'): void
    {
        TestRuntime::assertAuthorized();

        $deny = static fn (string $reason) => throw new DatabaseTargetViolation(
            "Conexión de pruebas '{$name}' rechazada: {$reason}"
        );

        foreach (self::strings($config) as $value) {
            if (self::isPlaceholder($value)) {
                $deny('contiene marcadores ${...} sin resolver.');
            }
        }

        if (trim(self::scalar($config['url'] ?? '')) !== '') {
            $deny('DB_URL debe estar vacío.');
        }

        $target = self::resolve($config);

        if ($target['driver'] !== 'mysql') {
            $deny('el driver debe ser mysql.');
        }
        if ($target['sockets'] !== ['']) {
            $deny('no se permiten sockets Unix.');
        }
        if ($target['hosts'] === [] || array_diff($target['hosts'], self::TEST_HOSTS) !== []) {
            $deny('el host no pertenece a la red Docker aislada de pruebas.');
        }
        if ($target['ports'] !== [self::TEST_PORT]) {
            $deny('el puerto debe ser '.self::TEST_PORT.'.');
        }
        if ($target['databases'] !== [self::TEST_DATABASE]) {
            $deny('la base debe ser exactamente '.self::TEST_DATABASE.'.');
        }
        if (count($target['usernames']) !== 1 || ! in_array($target['usernames'][0], self::TEST_USERS, true)) {
            $deny('el usuario no es una cuenta de pruebas autorizada.');
        }
        if (trim(self::scalar($config['password'] ?? '')) === '') {
            $deny('falta la credencial de pruebas.');
        }
    }

    public static function assertConfigurationNotCached(Application $app): void
    {
        if ($app->configurationIsCached()) {
            throw new DatabaseTargetViolation(
                'Existe bootstrap/cache/config.php; la configuración cacheada impide validar el destino de pruebas.'
            );
        }
    }

    /**
     * Runs right after configuration is loaded and before any service provider
     * is registered (see bootstrap/app.php).
     */
    public static function assertBootConfiguration(Application $app): void
    {
        if (! $app->environment('testing')) {
            return;
        }

        if (! TestRuntime::isAuthorized()) {
            if (! $app->runningInConsole()) {
                TestRuntime::assertAuthorized();
            }

            // Console only: ArtisanCommandGate limits this process to database-free commands.
            return;
        }

        self::assertConfigurationNotCached($app);

        if (config('database.default') !== 'mysql') {
            throw new DatabaseTargetViolation('En pruebas la conexión por defecto debe ser mysql.');
        }

        self::assertTestingConnection((array) config('database.connections.mysql'), 'mysql');
    }

    /**
     * Gate for clients that bypass Laravel's connection layer (raw PDO, mysql, mysqldump).
     */
    public static function assertExternalClientAllowed(array $config, string $name = 'mysql'): void
    {
        if (! self::isTestingProcess()) {
            return;
        }

        $app = function_exists('app') ? app() : null;
        if ($app instanceof Application) {
            self::assertConfigurationNotCached($app);
        }
        self::assertTestingConnection($config, $name);
    }

    /**
     * Endpoint-only contract for raw clients that do not select a schema
     * (identity probes, restore targets) while APP_ENV=testing.
     */
    public static function assertTestingEndpoint(string $host, string $port, string $socket): void
    {
        TestRuntime::assertAuthorized();

        if (trim($socket) !== ''
            || ! in_array(strtolower(trim($host)), self::TEST_HOSTS, true)
            || trim($port) !== self::TEST_PORT) {
            throw new DatabaseTargetViolation(
                'Endpoint MySQL rechazado en pruebas: solo se admiten hosts de la red Docker aislada por TCP '
                .self::TEST_PORT.'.'
            );
        }
    }

    public static function isTestingProcess(): bool
    {
        if (getenv('APP_ENV') === 'testing') {
            return true;
        }

        try {
            $app = function_exists('app') ? app() : null;

            return $app instanceof Application && $app->bound('env') && $app->environment('testing');
        } catch (Throwable) {
            return false;
        }
    }

    /**
     * Label that an operator must export to approve schema/data changes.
     */
    public static function approvalLabel(array $config): string
    {
        $target = self::resolve($config);

        if (count($target['databases']) !== 1 || $target['databases'][0] === '') {
            throw new DatabaseTargetViolation('No se pudo determinar una base de destino única.');
        }

        if ($target['sockets'] !== ['']) {
            if (count($target['sockets']) !== 1) {
                throw new DatabaseTargetViolation('La conexión mezcla sockets y hosts.');
            }
            $endpoint = 'unix:'.$target['sockets'][0];
        } else {
            if (count($target['hosts']) !== 1 || $target['hosts'][0] === '') {
                throw new DatabaseTargetViolation('No se pudo determinar un host de destino único.');
            }
            $endpoint = $target['hosts'][0];
        }

        foreach ([$target['databases'][0], $endpoint] as $value) {
            if (self::isPlaceholder($value)) {
                throw new DatabaseTargetViolation('El destino contiene marcadores ${...} sin resolver.');
            }
        }

        return $target['databases'][0].'@'.$endpoint;
    }

    public static function isProtectedDatabase(array $config): bool
    {
        $target = self::resolve($config);

        return array_intersect($target['databases'], self::PROTECTED_DATABASES) !== [];
    }

    public static function isProtectedServer(string $serverUuid): bool
    {
        return in_array(strtolower(trim($serverUuid)), self::PROTECTED_SERVER_UUIDS, true);
    }

    private static function scalar(mixed $value): string
    {
        if ($value === null) {
            return '';
        }
        if (! is_scalar($value)) {
            throw new DatabaseTargetViolation('La conexión contiene valores no escalares inesperados.');
        }

        return trim((string) $value);
    }

    private static function isPlaceholder(string $value): bool
    {
        return preg_match('/\$\{[^}]*\}/', $value) === 1;
    }

    /** @return list<string> */
    private static function strings(array $config): array
    {
        $values = [];
        array_walk_recursive($config, function (mixed $value) use (&$values): void {
            if (is_string($value)) {
                $values[] = $value;
            }
        });

        return $values;
    }
}
