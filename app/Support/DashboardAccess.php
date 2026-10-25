<?php

namespace App\Support;

use App\Models\Business;
use App\Models\User;

/**
 * Dashboards financieros: dueño y administrador del local (el admin secundario).
 * Preventa, cajero e inventario no. Cocina operativa no usa esto.
 */
final class DashboardAccess
{
    public static function allowed(): bool
    {
        $user = auth()->user();
        if (! $user instanceof User) {
            return false;
        }

        $tenant = self::tenant();
        if ($tenant instanceof Business) {
            if ((string) $tenant->owner_id === (string) $user->getKey()) {
                return true;
            }

            $role = $user->roleInBusiness((string) $tenant->getKey());
            if ($role === 'manager') {
                $role = 'supervisor';
            }

            return in_array($role, ['owner', 'admin'], true);
        }

        return Business::query()->where('owner_id', $user->getKey())->where('is_active', true)->exists()
            || $user->businesses()
                ->wherePivot('is_active', true)
                ->wherePivotIn('role', ['owner', 'admin'])
                ->exists();
    }

    protected static function tenant(): ?Business
    {
        try {
            $t = filament()->getTenant();
            if ($t instanceof Business) {
                return $t;
            }
        } catch (\Throwable) {
        }

        if (app()->bound('currentBusiness')) {
            $t = app('currentBusiness');
            if ($t instanceof Business) {
                return $t;
            }
        }

        return null;
    }
}
