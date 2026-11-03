<?php

namespace App\Services;

use Illuminate\Database\Connectors\MySqlConnector;

class VerifiedMysqlConnector extends MySqlConnector
{
    public function connect(array $config)
    {
        $config['options'] = array_replace($config['options'] ?? [], MysqlTlsPolicy::pdoOptions($config));

        return parent::connect($config);
    }

    public function createConnection($dsn, array $config, array $options)
    {
        $connection = parent::createConnection($dsn, $config, $options);
        MysqlTlsPolicy::assertEncrypted($connection, $config);

        return $connection;
    }
}
