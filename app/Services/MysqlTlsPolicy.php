<?php

namespace App\Services;

use PDO;
use RuntimeException;
use Symfony\Component\Process\Process;

class MysqlTlsPolicy
{
    public static function enabled(array $connection): bool
    {
        return (bool) ($connection['ssl_required'] ?? false)
            || (config('app.env') === 'production' && self::socket($connection) === '')
            || self::ca($connection) !== '';
    }

    public static function pdoOptions(array $connection): array
    {
        if (! self::enabled($connection)) {
            return [];
        }
        $host = strtolower(trim((string) ($connection['host'] ?? '')));
        if (self::socket($connection) !== '' || $host === '' || $host === 'localhost') {
            throw new RuntimeException('TLS MySQL requiere TCP explícito, no localhost ni socket Unix.');
        }
        $ca = self::ca($connection);
        if ($ca === '' || ! is_file($ca) || ! is_readable($ca) || strpbrk($ca, "\r\n\0") !== false) {
            throw new RuntimeException('TLS MySQL requiere una CA legible y una ruta válida.');
        }
        if (! extension_loaded('pdo_mysql')) {
            throw new RuntimeException('No está disponible el soporte PDO MySQL requerido para TLS.');
        }

        return [
            PDO::MYSQL_ATTR_SSL_CA => $ca,
            PDO::MYSQL_ATTR_SSL_VERIFY_SERVER_CERT => true,
        ];
    }

    public static function assertEncrypted(PDO $pdo, array $connection): void
    {
        if (self::enabled($connection)) {
            $status = $pdo->query("SHOW SESSION STATUS LIKE 'Ssl_cipher'")->fetch(PDO::FETCH_NUM);
            if (empty($status[1])) {
                throw new RuntimeException('MySQL no negoció TLS; se rechaza la conexión.');
            }
        }
    }

    public static function clientOptions(array $connection, string $binary): array
    {
        if (! self::enabled($connection)) {
            return [];
        }
        self::pdoOptions($connection);
        $version = new Process([$binary, '--no-defaults', '--version']);
        $version->setTimeout(5);
        $version->mustRun();
        $options = [
            'protocol=tcp',
            'ssl-ca="'.self::quote(self::ca($connection)).'"',
        ];
        if (str_contains($version->getOutput(), 'MariaDB')) {
            return [...$options, 'ssl=1', 'ssl-verify-server-cert=1'];
        }
        if (! preg_match('/(?:mysql|mysqldump).*Ver\s+(?:8\.|9\.)/i', $version->getOutput())) {
            throw new RuntimeException('Cliente MySQL TLS no reconocido; requiere MySQL 8/9 o MariaDB.');
        }

        return [...$options, 'ssl-mode=VERIFY_IDENTITY'];
    }

    public static function ca(array $connection): string
    {
        return trim((string) ($connection['ssl_ca']
            ?? ($connection['options'][PDO::MYSQL_ATTR_SSL_CA] ?? '')));
    }

    public static function socket(array $connection): string
    {
        return trim((string) ($connection['socket'] ?? $connection['unix_socket'] ?? ''));
    }

    public static function quote(string $value): string
    {
        if (strpbrk($value, "\r\n\0") !== false) {
            throw new RuntimeException('Valor inválido en el archivo privado de conexión MySQL.');
        }

        return str_replace(['\\', '"'], ['\\\\', '\"'], $value);
    }
}
