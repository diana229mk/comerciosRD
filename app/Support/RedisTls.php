<?php

namespace App\Support;

/**
 * TLS opcional para Redis. El default sigue en TCP claro dentro de la red
 * privada. Managed Caching de DigitalOcean exige scheme=tls y CA.
 */
class RedisTls
{
    /**
     * @param  array<string, mixed>  $connection
     * @return array<string, mixed>
     */
    public static function apply(array $connection, ?string $scheme, ?string $ca): array
    {
        if ($scheme !== 'tls') {
            return $connection;
        }

        $connection['scheme'] = 'tls';
        $connection['context'] = [
            'stream' => array_filter([
                'verify_peer' => true,
                'verify_peer_name' => true,
                'cafile' => is_string($ca) && $ca !== '' ? $ca : null,
            ], static fn ($value) => $value !== null),
        ];

        return $connection;
    }

    /**
     * @return array<string, mixed>
     */
    public static function connection(string $database): array
    {
        return self::apply([
            'url' => env('REDIS_URL'),
            'host' => env('REDIS_HOST', '127.0.0.1'),
            'username' => env('REDIS_USERNAME'),
            'password' => env('REDIS_PASSWORD'),
            'port' => env('REDIS_PORT', '6379'),
            'database' => $database,
            'max_retries' => env('REDIS_MAX_RETRIES', 3),
            'backoff_algorithm' => env('REDIS_BACKOFF_ALGORITHM', 'decorrelated_jitter'),
            'backoff_base' => env('REDIS_BACKOFF_BASE', 100),
            'backoff_cap' => env('REDIS_BACKOFF_CAP', 1000),
        ], env('REDIS_SCHEME'), env('REDIS_SSL_CA'));
    }
}
