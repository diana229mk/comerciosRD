<?php

namespace Tests\Support;

use App\Support\DatabaseTargetPolicy;
use App\Support\DatabaseTargetViolation;
use App\Support\ProcessEnvironment;
use App\Support\TestRuntime;

/**
 * Runs before Laravel is created (PHPUnit bootstrap and fixtures) using only
 * the process environment, so nothing can connect before the target is known.
 */
final class PreBootGuard
{
    /**
     * @param  callable(string):(string|false)|null  $env
     */
    public static function assertTestProcess(?callable $env = null, ?string $basePath = null): void
    {
        ProcessEnvironment::capture();
        $env ??= static fn (string $key): string|false => getenv($key);
        $basePath ??= dirname(__DIR__, 2);

        $deny = static fn (string $reason) => throw new DatabaseTargetViolation(
            'Pruebas bloqueadas antes de arrancar Laravel: '.$reason
        );

        if ($env('APP_ENV') !== 'testing') {
            $deny('APP_ENV debe ser testing.');
        }

        TestRuntime::assertAuthorized();

        if ($env('DB_CONNECTION') !== 'mysql') {
            $deny('DB_CONNECTION debe ser mysql.');
        }
        if ($env('DB_DATABASE') !== DatabaseTargetPolicy::TEST_DATABASE) {
            $deny('DB_DATABASE debe ser '.DatabaseTargetPolicy::TEST_DATABASE.'.');
        }
        if (! in_array(strtolower((string) $env('DB_HOST')), DatabaseTargetPolicy::TEST_HOSTS, true)) {
            $deny('DB_HOST no pertenece a la red Docker aislada.');
        }
        foreach (['DB_URL', 'DB_SOCKET'] as $key) {
            if (! in_array($env($key), [false, ''], true)) {
                $deny("{$key} debe estar vacío.");
            }
        }
        if (! in_array($env('DB_PORT'), [false, '', DatabaseTargetPolicy::TEST_PORT], true)) {
            $deny('DB_PORT debe ser '.DatabaseTargetPolicy::TEST_PORT.'.');
        }
        $username = $env('DB_TEST_USERNAME');
        if (! in_array($username, [false, ''], true) && ! in_array($username, DatabaseTargetPolicy::TEST_USERS, true)) {
            $deny('DB_TEST_USERNAME no es una cuenta de pruebas autorizada.');
        }
        if (is_file($basePath.'/bootstrap/cache/config.php')) {
            $deny('existe bootstrap/cache/config.php.');
        }
    }
}
