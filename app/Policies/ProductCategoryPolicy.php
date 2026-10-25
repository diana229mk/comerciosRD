<?php

namespace App\Policies;

use App\Models\User;
use Illuminate\Auth\Access\Response;
use Illuminate\Database\Eloquent\Model;

class ProductCategoryPolicy extends TenantPolicy
{
    protected string $resource = 'products';

    public function delete(User $user, Model $record): Response|bool
    {
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
}
