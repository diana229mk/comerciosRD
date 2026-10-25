<?php

namespace App\Policies;

use App\Models\Product;
use App\Models\User;
use Illuminate\Auth\Access\Response;
use Illuminate\Database\Eloquent\Model;

class ProductPolicy extends TenantPolicy
{
    /**
     * Solo el dueño, admin o gerente puede eliminar productos.
     */
    public function delete(User $user, Model $record): Response|bool
    {
        /** @var Product $record */
        $this->denyIfForeignTenant($record);

        $role = $user->businesses()
            ->where('businesses.id', $record->business_id)
            ->first()
            ?->pivot
            ?->role;

        if (! in_array($role, ['owner', 'admin', 'manager'], true)) {
            return false;
        }

        return $this->userBelongsToRecordBusiness($user, $record)
            ? Response::allow()
            : Response::denyAsNotFound();
    }

    /**
     * Cajero no puede ver el precio de costo.
     */
    public function viewCost(User $user, Product $product): bool
    {
        $this->denyIfForeignTenant($product);

        $role = $user->businesses()
            ->where('businesses.id', $product->business_id)
            ->first()
            ?->pivot
            ?->role;

        return in_array($role, ['owner', 'admin', 'manager'], true);
    }
}
