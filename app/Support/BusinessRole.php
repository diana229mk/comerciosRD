<?php

namespace App\Support;

use App\Models\User;
use Filament\Facades\Filament;

/**
 * RBAC por rol de pivot user_business (mínimo privilegio).
 */
final class BusinessRole
{
    /**
     * Roles asignables en el panel Admin (UI).
     *
     * @return array<string, string>
     */
    public static function options(): array
    {
        return [
            'owner' => 'Dueño (todo el acceso)',
            'admin' => 'Administrador',
            'supervisor' => 'Encargado / Supervisor',
            'cashier' => 'Cajero',
            'inventory' => 'Inventario / Almacén',
            'accountant' => 'Contable',
            'seller' => 'Vendedor / Preventa',
            'viewer' => 'Solo lectura',
        ];
    }

    /**
     * Roles que pueden gestionar usuarios del negocio.
     *
     * @return list<string>
     */
    public static function manageUsersRoles(): array
    {
        return ['owner', 'admin'];
    }

    public static function canManageUsers(User $user, ?string $businessId = null): bool
    {
        $role = self::currentRole($user, $businessId);

        if ($role === null) {
            return false;
        }

        return in_array($role, self::manageUsersRoles(), true)
            || self::allows($user, 'employees.*', $businessId)
            || self::allows($user, 'employees.manage', $businessId);
    }

    public static function label(?string $role): string
    {
        if ($role === null || $role === '') {
            return '—';
        }

        return self::options()[$role]
            ?? match ($role) {
                'manager' => 'Encargado (legacy)',
                'delivery' => 'Delivery',
                default => $role,
            };
    }

    /**
     * Etiqueta corta para el chip de contexto / top bar.
     */
    public static function shortLabel(?string $role): string
    {
        return match ($role) {
            'owner' => 'Dueño',
            'admin' => 'Administrador',
            'supervisor', 'manager' => 'Encargado',
            'cashier' => 'Cajero',
            'inventory' => 'Inventario',
            'accountant' => 'Contable',
            'seller' => 'Vendedor',
            'viewer' => 'Solo lectura',
            default => self::label($role),
        };
    }

    public static function color(?string $role): string
    {
        return match ($role) {
            'owner' => 'danger',
            'admin' => 'warning',
            'supervisor', 'manager' => 'info',
            'cashier' => 'success',
            'inventory' => 'primary',
            'accountant' => 'purple',
            'seller' => 'info',
            'viewer' => 'gray',
            default => 'gray',
        };
    }

    /**
     * ¿El rol actual del usuario puede realizar la acción lógica?
     * Acción: "products.view", "sales.create", "expenses.*", etc.
     */
    public static function allows(User $user, string $ability, ?string $businessId = null): bool
    {
        $role = self::currentRole($user, $businessId);

        if ($role === null) {
            return false;
        }

        if ($role === 'owner') {
            return true;
        }

        $map = config('security.roles', []);
        $allowed = $map[$role] ?? [];

        if (in_array('*', $allowed, true)) {
            return true;
        }

        // Match exacto o wildcard de recurso (products.*)
        if (in_array($ability, $allowed, true)) {
            return true;
        }

        [$resource] = array_pad(explode('.', $ability, 2), 2, null);
        if ($resource && in_array($resource.'.*', $allowed, true)) {
            return true;
        }

        return false;
    }

    public static function currentRole(User $user, ?string $businessId = null): ?string
    {
        $businessId ??= self::resolveBusinessId();

        if ($businessId === null) {
            return null;
        }

        return $user->roleInBusiness($businessId);
    }

    public static function resolveBusinessId(): ?string
    {
        try {
            $tenant = Filament::getTenant();
            if ($tenant) {
                return (string) $tenant->getKey();
            }
        } catch (\Throwable) {
        }

        if (app()->bound('currentBusinessId')) {
            $id = app('currentBusinessId');

            return $id ? (string) $id : null;
        }

        return session('current_business_id')
            ? (string) session('current_business_id')
            : (auth()->user()?->current_business_id
                ? (string) auth()->user()->current_business_id
                : null);
    }
}
