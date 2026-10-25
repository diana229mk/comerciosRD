<?php

namespace App\Contracts;

interface MysqlServerIdentityReader
{
    /**
     * @return array{database:?string, server_uuid:string, server_port:string, server_socket:string}
     */
    public function active(): array;

    /**
     * @param  array{host:string,port:string,socket:string,username:string,password:string}  $connection
     * @return array{server_uuid:string, server_port:string, server_socket:string}
     */
    public function target(array $connection): array;
}
