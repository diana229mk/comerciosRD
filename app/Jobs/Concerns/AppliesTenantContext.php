<?php

namespace App\Jobs\Concerns;

use App\Support\TenantContext;

trait AppliesTenantContext
{
    /**
     * @template T
     * @param  callable(\App\Models\Business): T  $callback
     * @return T
     */
    protected function withTenant(string $businessId, callable $callback): mixed
    {
        return TenantContext::run($businessId, $callback);
    }
}
