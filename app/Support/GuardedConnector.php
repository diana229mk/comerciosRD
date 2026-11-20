<?php

namespace App\Support;

use Illuminate\Database\Connectors\ConnectorInterface;

/**
 * Wraps a framework connector so no PDO is opened before DatabaseConnectionGuard
 * validates the effective configuration.
 */
final class GuardedConnector implements ConnectorInterface
{
    public function __construct(private readonly ConnectorInterface $inner) {}

    public function connect(array $config)
    {
        DatabaseConnectionGuard::authorizeConnect($config);

        return $this->inner->connect($config);
    }
}
