<?php

namespace App\Policies;

/**
 * Clientes del negocio.
 */
class CustomerPolicy extends TenantPolicy
{
    protected string $resource = 'customers';
}
