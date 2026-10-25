<?php

namespace App\Policies;

use App\Models\User;
use Illuminate\Auth\Access\Response;
use Illuminate\Database\Eloquent\Model;

/**
 * Órdenes del negocio (por nombre / por número).
 */
class OrderPolicy extends TenantPolicy
{
    public function delete(User $user, Model $record): Response|bool
    {
        $this->denyIfForeignTenant($record);

        $role = $user->businesses()
            ->where('businesses.id', $record->business_id)
            ->first()
            ?->pivot
            ?->role;

        // Cajero puede cancelar; eliminar soft solo manager+
        if (! in_array($role, ['owner', 'admin', 'manager', 'cashier'], true)) {
            return false;
        }

        return $this->userBelongsToRecordBusiness($user, $record)
            ? Response::allow()
            : Response::denyAsNotFound();
    }
}
