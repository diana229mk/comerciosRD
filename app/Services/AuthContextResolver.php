<?php

namespace App\Services;

use App\Models\Business;
use App\Models\User;
use Illuminate\Http\Request;

/**
 * Punto único que resuelve y reescribe el contexto de autenticación del
 * usuario en la sesión: negocio, rol, sucursal y panel destino.
 *
 * Debe llamarse siempre DESPUÉS de session()->regenerate() (o al restaurar
 * sesión existente). Es idempotente: reinvocarlo no rompe nada.
 *
 * FASE 3 del parche del bug: los usuarios secundarios "no entraban" porque
 * al regenerar la sesión se perdía el contexto de negocio y ningún flujo lo
 * reescribía de vuelta. Este servicio garantiza ese paso.
 */
class AuthContextResolver
{
    public const STATUS_OK = 'ok';

    public const STATUS_INACTIVE = 'inactive';

    public const STATUS_NO_BUSINESS = 'no_business';

    public const SESSION_BUSINESS_ID = 'current_business_id';

    public const SESSION_BRANCH_ID = 'current_branch_id';

    public const SESSION_ROLE = 'current_business_role';

    public const SESSION_PANEL_DESTINATION = 'panel_destino';

    /**
     * Resuelve el contexto y lo escribe en la sesión.
     *
     * @return array{status: string, business_id: ?string, role: ?string, branch_id: ?string, panel_url: ?string, message: ?string}
     */
    public function apply(User $user, ?Business $preferredTenant = null): array
    {
        if (($user->is_active ?? true) === false) {
            $this->forgetSession();

            return [
                'status' => self::STATUS_INACTIVE,
                'business_id' => null,
                'role' => null,
                'branch_id' => null,
                'panel_url' => null,
                'message' => 'Tu cuenta está desactivada. Contacta al dueño del negocio.',
            ];
        }

        // Master admin no necesita tenant.
        if ((bool) ($user->is_master_admin ?? false) === true && $preferredTenant === null) {
            $this->forgetSession();

            return [
                'status' => self::STATUS_OK,
                'business_id' => null,
                'role' => 'master',
                'branch_id' => null,
                'panel_url' => route('filament.master.pages.dashboard', absolute: false),
                'message' => null,
            ];
        }

        $tenant = $this->resolveTenant($user, $preferredTenant);

        if ($tenant === null) {
            $this->forgetSession();

            return [
                'status' => self::STATUS_NO_BUSINESS,
                'business_id' => null,
                'role' => null,
                'branch_id' => null,
                'panel_url' => null,
                'message' => 'Tu cuenta no tiene ningún negocio asignado. Contacta al dueño.',
            ];
        }

        $role = $user->roleInBusiness((string) $tenant->getKey());
        // FASE S1 sucursales: leer branch_id del pivot; si no hay y el negocio
        // tiene una sucursal principal activa, autoseleccionar esa.
        // FASE H2: respetar selección manual del switcher (S4b) si el user
        // tiene permiso para cambiar de sucursal Y la selección actual
        // pertenece a este tenant. Evita que `apply()` sobreescriba el
        // switcher en cada request. También cierra fuga cross-tenant:
        // si SESSION_BRANCH_ID es de OTRO negocio, se descarta.
        $branchId = $this->resolveBranchForContext($user, $tenant);
        $panelUrl = $this->panelDestination($user, $tenant, $role);

        session([
            self::SESSION_BUSINESS_ID => (string) $tenant->getKey(),
            self::SESSION_ROLE => $role,
            self::SESSION_BRANCH_ID => $branchId,
            self::SESSION_PANEL_DESTINATION => $panelUrl,
        ]);

        app()->instance('currentBusinessId', (string) $tenant->getKey());
        app()->instance('currentBusiness', $tenant);

        // Persistir preferencia para el próximo login.
        if ((string) ($user->current_business_id ?? '') !== (string) $tenant->getKey()) {
            $user->forceFill(['current_business_id' => (string) $tenant->getKey()])->saveQuietly();
        }

        return [
            'status' => self::STATUS_OK,
            'business_id' => (string) $tenant->getKey(),
            'role' => $role,
            'branch_id' => $branchId,
            'panel_url' => $panelUrl,
            'message' => null,
        ];
    }

    /**
     * Rehidrata el contexto si falta en la sesión (por ejemplo, tras un
     * config:cache o una request en la que la cookie sobrevivió pero la
     * sesión perdió los valores). Devuelve true si hay contexto útil.
     */
    public function rehydrateIfMissing(User $user, ?Business $preferredTenant = null): bool
    {
        if (session()->has(self::SESSION_BUSINESS_ID)
            && (string) session(self::SESSION_BUSINESS_ID) !== ''
        ) {
            return true;
        }

        return $this->apply($user, $preferredTenant)['status'] === self::STATUS_OK;
    }

    protected function resolveTenant(User $user, ?Business $preferredTenant): ?Business
    {
        if ($preferredTenant instanceof Business
            && $user->canAccessTenant($preferredTenant)
        ) {
            return $preferredTenant;
        }

        /** @var ?Business $default */
        $default = $user->activeBusinesses()
            ->when(
                (string) ($user->current_business_id ?? '') !== '',
                fn ($q) => $q->orderByRaw(
                    'CASE WHEN businesses.id = ? THEN 0 ELSE 1 END',
                    [$user->current_business_id]
                )
            )
            ->first();

        return $default;
    }

    public function panelDestination(User $user, Business $tenant, ?string $role = null): string
    {
        if ($role === null) {
            $role = $user->roleInBusiness((string) $tenant->getKey())
                ?? session(self::SESSION_ROLE);
        }

        return $this->resolvePanelDestination($user, $tenant, $role);
    }

    protected function resolvePanelDestination(User $user, Business $tenant, ?string $role): string
    {
        // Usar el rol YA resuelto (en /admin/login no hay tenant de Filament,
        // así que isStrictCashier() devolvía false y el cajero caía al dashboard).
        $normalized = $role === 'manager' ? 'supervisor' : $role;

        $slug = (string) $tenant->slug;

        if ($normalized === 'cashier') {
            try {
                return route('pos.terminal', ['tenant' => $slug], absolute: false);
            } catch (\Throwable) {
                return '/caja/'.$slug;
            }
        }

        if ($normalized === 'inventory') {
            try {
                return route('filament.admin.resources.products.index', ['tenant' => $slug], absolute: false);
            } catch (\Throwable) {
                return '/admin/'.$slug.'/products';
            }
        }

        if ($normalized === 'seller') {
            try {
                return route('filament.admin.pages.preventa', ['tenant' => $slug], absolute: false);
            } catch (\Throwable) {
                return '/admin/'.$slug.'/preventa';
            }
        }

        try {
            return route('filament.admin.pages.dashboard', ['tenant' => $slug], absolute: false);
        } catch (\Throwable) {
            return '/admin/'.$slug;
        }
    }

    protected function forgetSession(): void
    {
        session()->forget([
            self::SESSION_BUSINESS_ID,
            self::SESSION_ROLE,
            self::SESSION_BRANCH_ID,
            self::SESSION_PANEL_DESTINATION,
        ]);
    }

    /**
     * FASE H2 — Resuelve el branch_id contextual respetando el switcher.
     *
     * Orden:
     *  1) Si SESSION_BRANCH_ID actual apunta a una sucursal VÁLIDA de $tenant
     *     y el user tiene permiso para cambiar de sucursal (owner o rol
     *     admin/supervisor/manager) → mantener (respeta el switcher S4b).
     *  2) Si el user tiene branch_id en el pivot user_business → usar ese.
     *  3) Si el negocio tiene mainBranch activa → autoseleccionar.
     *  4) null (fallback seguro).
     *
     * Si SESSION_BRANCH_ID pertenecía a OTRO negocio (fuga cross-tenant),
     * queda descartada porque la validación del paso 1 falla y el paso 2
     * escribe el pivot del tenant correcto.
     */
    protected function resolveBranchForContext(User $user, Business $tenant): ?string
    {
        $tenantId = (string) $tenant->getKey();

        $currentSessionBranch = session(self::SESSION_BRANCH_ID);
        if ($this->userCanSwitchBranch($user, $tenant)) {
            if (is_string($currentSessionBranch)
                && $currentSessionBranch !== ''
                && $this->branchBelongsToBusiness($currentSessionBranch, $tenantId)
            ) {
                return $currentSessionBranch;
            }

            // Vacío = dashboard completo (todas las sucursales).
            return null;
        }

        $pivotBranch = $user->branchInBusiness($tenantId);
        if ($pivotBranch !== null) {
            return $pivotBranch;
        }

        try {
            $main = $tenant->mainBranch();
            if ($main !== null) {
                return (string) $main->getKey();
            }
        } catch (\Throwable) {
            // Tabla branches puede no existir aún en tests / migraciones.
        }

        return null;
    }

    /**
     * ¿El usuario tiene permiso para cambiar la sucursal activa vía switcher?
     * Sólo owner + roles administrativos. Cajeros/vendedores/inventario
     * quedan atados al branch de su pivot.
     */
    protected function userCanSwitchBranch(User $user, Business $tenant): bool
    {
        if ((string) ($tenant->owner_id ?? '') === (string) $user->getKey()) {
            return true;
        }

        $pivot = $user->businesses()
            ->where('businesses.id', $tenant->getKey())
            ->first()
            ?->pivot;

        if ($pivot && filled($pivot->branch_id)) {
            return false;
        }

        $role = $pivot?->role;

        if ($role === 'manager') {
            $role = 'supervisor';
        }

        return in_array($role, ['owner', 'admin', 'supervisor'], true);
    }

    /**
     * ¿La sucursal existe y pertenece al negocio dado?
     * Consulta ligera (solo verifica existencia).
     */
    protected function branchBelongsToBusiness(string $branchId, string $businessId): bool
    {
        try {
            return \App\Models\Branch::query()
                ->whereKey($branchId)
                ->where('business_id', $businessId)
                ->where('is_active', true)
                ->exists();
        } catch (\Throwable) {
            return false;
        }
    }
}
