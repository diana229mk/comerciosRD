<?php

namespace App\Services;

use App\Contracts\MysqlServerIdentityReader;
use RuntimeException;

class PortabilityRestoreTargetGuard
{
    public function __construct(private MysqlServerIdentityReader $identityReader) {}

    /**
     * Rejects a target schema on the active MySQL server before any restore work.
     *
     * @param  array{host:string,port:string,socket:string,username:string,password:string}  $targetConnection
     */
    public function assertDestinationIsNotActive(string $targetDatabase, array $targetConnection): void
    {
        try {
            $active = $this->identityReader->active();
            $target = $this->identityReader->target($targetConnection);

            if ($targetDatabase === ''
                || ($active['database'] ?? null) === null
                || ($active['server_uuid'] ?? '') === ''
                || ($active['server_port'] ?? '') === ''
                || ($active['server_socket'] ?? '') === ''
                || ($target['server_uuid'] ?? '') === ''
                || ($target['server_port'] ?? '') === ''
                || ($target['server_socket'] ?? '') === '') {
                throw new RuntimeException;
            }

            $sameServer = hash_equals($active['server_uuid'], $target['server_uuid']);
            $sameConfiguredEndpoint = $this->sameConfiguredEndpoint($targetConnection);
            $sameSchema = hash_equals((string) $active['database'], $targetDatabase);

            if ($sameSchema && ($sameServer || $sameConfiguredEndpoint)) {
                throw new RuntimeException;
            }
        } catch (\Throwable) {
            throw new RuntimeException(
                'Restauración rechazada: no fue posible demostrar que el destino sea distinto de la base activa.'
            );
        }
    }

    /**
     * @param  array{host:string,port:string,socket:string,username:string,password:string}  $targetConnection
     */
    private function sameConfiguredEndpoint(array $targetConnection): bool
    {
        $active = config('database.connections.mysql');
        $activeSocket = trim((string) ($active['unix_socket'] ?? ''));
        $targetSocket = trim($targetConnection['socket']);

        if ($activeSocket !== '' || $targetSocket !== '') {
            return $activeSocket !== '' && $targetSocket !== '' && $activeSocket === $targetSocket;
        }

        $activeHost = strtolower(rtrim(trim((string) ($active['host'] ?? '')), '.'));
        $targetHost = strtolower(rtrim(trim($targetConnection['host']), '.'));
        $activePort = (string) ($active['port'] ?? '3306');
        $targetPort = $targetConnection['port'];

        return $activeHost !== ''
            && $targetHost !== ''
            && $activeHost === $targetHost
            && $activePort === $targetPort;
    }
}
