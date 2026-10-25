<?php

namespace App\Policies;

use App\Models\User;
use Illuminate\Auth\Access\Response;
use Illuminate\Database\Eloquent\Model;

/**
 * Cuentas por pagar (Payable).
 */
class PayablePolicy extends TenantPolicy
{
    public function delete(User $user, Model $record): Response|bool
    {
        $this->denyIfForeignTenant($record);

        $role = $user->businesses()
            ->where('businesses.id', $record->business_id)
            ->first()
            ?->pivot
            ?->role;

        if (! in_array($role, ['owner', 'admin', 'manager', 'accountant'], true)) {
            return false;
        }

        return $this->userBelongsToRecordBusiness($user, $record)
            ? Response::allow()
            : Response::denyAsNotFound();
    }
}
