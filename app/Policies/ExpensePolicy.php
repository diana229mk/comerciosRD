<?php

namespace App\Policies;

use App\Models\User;
use Illuminate\Auth\Access\Response;
use Illuminate\Database\Eloquent\Model;

/**
 * Gastos del negocio (contabilidad).
 */
class ExpensePolicy extends TenantPolicy
{
    public function delete(User $user, Model $record): Response|bool
    {
        $this->denyIfForeignTenant($record);

        if ($denied = $this->denyIfForeignBranch($user, $record)) {
            return $denied;
        }

        $role = $user->businesses()
            ->where('businesses.id', $record->business_id)
            ->first()
            ?->pivot
            ?->role;

        // Cajero no elimina gastos
        if (! in_array($role, ['owner', 'admin', 'manager', 'accountant'], true)) {
            return false;
        }

        return $this->userBelongsToRecordBusiness($user, $record)
            ? Response::allow()
            : Response::denyAsNotFound();
    }
}
