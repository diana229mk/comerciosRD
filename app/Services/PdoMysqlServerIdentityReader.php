<?php

namespace App\Services;

use App\Contracts\MysqlServerIdentityReader;
use Illuminate\Support\Facades\DB;
use PDO;
use RuntimeException;
use Throwable;

class PdoMysqlServerIdentityReader implements MysqlServerIdentityReader
{
    public function active(): array
    {
        try {
            $identity = DB::connection('mysql')->selectOne(
                'SELECT DATABASE() AS database_name, @@server_uuid AS server_uuid, @@port AS server_port, @@socket AS server_socket'
            );

            return [
                'database' => $identity?->database_name,
                'server_uuid' => (string) ($identity?->server_uuid ?? ''),
                'server_port' => (string) ($identity?->server_port ?? ''),
                'server_socket' => (string) ($identity?->server_socket ?? ''),
            ];
        } catch (Throwable) {
            throw new RuntimeException('No se pudo verificar la identidad de la base de datos activa.');
        }
    }

    public function target(array $connection): array
    {
        $socket = trim($connection['socket']);
        $host = trim($connection['host']);
        $port = trim($connection['port']);

        if ($socket === '') {
            if ($host === '' || preg_match('/^[0-9]{1,5}$/', $port) !== 1 || (int) $port > 65535
                || str_contains($host, ';')) {
                throw new RuntimeException('La conexión de destino no tiene un endpoint MySQL verificable.');
            }

            $dsn = 'mysql:host='.$host.';port='.$port.';charset=utf8mb4';
        } else {
            if (str_contains($socket, ';')) {
                throw new RuntimeException('La conexión de destino no tiene un socket MySQL verificable.');
            }

            $dsn = 'mysql:unix_socket='.$socket.';charset=utf8mb4';
        }

        try {
            $pdo = new PDO($dsn, $connection['username'], $connection['password'], [
                PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
                PDO::ATTR_TIMEOUT => 5,
            ]);
            $identity = $pdo->query(
                'SELECT @@server_uuid AS server_uuid, @@port AS server_port, @@socket AS server_socket'
            )->fetch(PDO::FETCH_ASSOC);

            return [
                'server_uuid' => (string) ($identity['server_uuid'] ?? ''),
                'server_port' => (string) ($identity['server_port'] ?? ''),
                'server_socket' => (string) ($identity['server_socket'] ?? ''),
            ];
        } catch (Throwable) {
            throw new RuntimeException('No se pudo verificar la identidad del servidor MySQL de destino.');
        }
    }
}
