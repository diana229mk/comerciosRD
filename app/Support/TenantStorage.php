<?php

namespace App\Support;

/**
 * Carpetas de archivos por negocio.
 *
 * Las fotos/documentos nuevos van a `{base}/{business_id}/…`.
 * Las rutas viejas (`products/foto.png`) siguen sirviendo: no se reescriben.
 */
final class TenantStorage
{
    public static function currentBusinessId(): ?string
    {
        try {
            if (function_exists('filament')) {
                $tenant = filament()->getTenant();
                $key = $tenant?->getKey();
                if ($key !== null && $key !== '') {
                    return (string) $key;
                }
            }
        } catch (\Throwable) {
        }

        if (app()->bound('currentBusinessId')) {
            $bound = app('currentBusinessId');
            if (is_string($bound) && $bound !== '') {
                return $bound;
            }
        }

        $fromSession = session('current_business_id');
        if (is_string($fromSession) && $fromSession !== '') {
            return $fromSession;
        }

        return null;
    }

    public static function directory(string $base, ?string $businessId = null): string
    {
        $base = trim($base, '/');
        $id = $businessId ?? self::currentBusinessId();
        if (! is_string($id) || $id === '' || ! preg_match('/^[A-Za-z0-9._-]+$/', $id)) {
            return $base;
        }

        return $base.'/'.$id;
    }
}
