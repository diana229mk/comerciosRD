<?php

namespace App\Support;


/**
 * Proves that the process runs inside the authorized Docker test image:
 * a root-owned, read-only marker baked by Dockerfile.testing, an explicit
 * opt-in variable set by the test compose services, and a container runtime.
 */
final class TestRuntime
{
    public const ID = 'comercios-rd-isolated-tests/v1';

    public const VARIABLE = 'COMERCIOS_TEST_RUNTIME';

    public const MARKER = '/etc/comercios-rd/test-runtime.json';

    public const CONTAINER_MARKER = '/.dockerenv';

    public static function failure(
        ?string $declared = null,
        string $marker = self::MARKER,
        string $containerMarker = self::CONTAINER_MARKER,
    ): ?string {
        $declared ??= ProcessEnvironment::get(self::VARIABLE);

        if ($declared !== self::ID) {
            return self::VARIABLE.' no declara el runtime de pruebas autorizado.';
        }

        clearstatcache(true, $marker);
        if (! is_file($marker) || is_link($marker)) {
            return 'Falta el contrato del runtime de pruebas.';
        }

        $owner = @fileowner($marker);
        $permissions = @fileperms($marker);
        if ($owner !== 0 || $permissions === false || ($permissions & 0222) !== 0) {
            return 'El contrato del runtime de pruebas no pertenece a root o es modificable.';
        }

        $contract = json_decode((string) @file_get_contents($marker), true);
        if (! is_array($contract) || ($contract['id'] ?? null) !== self::ID) {
            return 'El contrato del runtime de pruebas no coincide.';
        }

        if (! file_exists($containerMarker)) {
            return 'El proceso no se ejecuta dentro de un contenedor.';
        }

        return null;
    }

    public static function isAuthorized(): bool
    {
        return self::failure() === null;
    }

    public static function assertAuthorized(): void
    {
        $failure = self::failure();

        if ($failure !== null) {
            throw new DatabaseTargetViolation(
                'Runtime de pruebas no autorizado: '.$failure
                .' Ejecute las pruebas solo mediante los servicios Docker aislados.'
            );
        }
    }
}
