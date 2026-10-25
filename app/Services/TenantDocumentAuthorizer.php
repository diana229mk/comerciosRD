<?php

namespace App\Services;

use App\Models\Business;
use App\Models\User;
use Illuminate\Database\Eloquent\Model;

class TenantDocumentAuthorizer
{
    public function authorizeTenant(string $tenantSlug, string $permission): Business
    {
        $business = Business::query()
            ->where('slug', $tenantSlug)
            ->where('is_active', true)
            ->firstOrFail();

        $this->authorizeBusiness($business, $permission);

        return $business;
    }

    public function authorizeBusiness(Business $business, string $permission): void
    {
        $user = request()->user();
        abort_unless($user instanceof User && (bool) $user->is_active, 403);
        abort_unless($business->is_active && $user->canAccessTenant($business), 404);

        $role = $user->roleInBusiness((string) $business->getKey());
        if ($role === 'manager') {
            $role = 'supervisor';
        }
        $permissions = config("permissions.roles.{$role}", []);
        abort_unless(
            is_array($permissions)
                && (in_array('*', $permissions, true) || in_array($permission, $permissions, true)),
            403,
        );
    }

    public function assertRecordBelongsToBusiness(Model $record, Business $business): void
    {
        abort_unless((string) $record->business_id === (string) $business->getKey(), 404);
    }
}
