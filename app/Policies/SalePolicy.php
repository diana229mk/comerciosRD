<?php

namespace App\Policies;

use App\Models\User;
use App\Support\BusinessRole;

/**
 * Ventas: no se editan; se cancelan con permiso sales.cancel.
 */
class SalePolicy extends TenantPolicy
{
    protected string $resource = 'sales';

    public function update(User $user, $record): bool
    {
        $this->denyIfForeignTenant($record);

        return false;
    }

    public function cancel(User $user, $record): bool
    {
        $this->denyIfForeignTenant($record);

        if ($this->denyIfForeignBranch($user, $record)) {
            return false;
        }

        return $this->userOwnsTenantContext($user, $record)
            && BusinessRole::allows($user, 'sales.cancel', (string) $record->business_id);
    }
}
