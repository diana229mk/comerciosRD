<?php

namespace App\Services;

use App\Models\Business;
use App\Models\User;
use Illuminate\Auth\Access\AuthorizationException;

/**
 * FASE H1 — Cache request-scoped del contexto de acceso por sucursal.
 *
 * Antes de H1, cada query de Sale/Order/Expense/CashSession/Purchase (scope
 * S4a) y cada policy check (S4c) hacía:
 *   - 1 SELECT a `businesses` (para leer features)
 *   - 1 SELECT a `user_business` (para leer role + branch_id del pivot)
 *
 * En un dashboard Filament con 15 tablas + widgets = 30-60 round-trips
 * redundantes por request.
 *
 * Este singleton resuelve UNA vez por (user × business) y devuelve un
 * snapshot inmutable. El scope y la policy leen del snapshot.
 *
 * El singleton se limpia:
 *   - Al final de cada request HTTP (Laravel bind por request).
 *   - Manualmente vía `forget($businessId)` cuando el switcher cambia branch
 *     o el resolver rehidrata contexto (ver AuthContextResolver::apply).
 */
class BranchAccessContext
{
    /** Roles con visión completa del negocio (bypass del filtro por sucursal). */
    private const ADMIN_ROLES = ['owner', 'admin', 'supervisor', 'manager'];

    /**
     * Cache por clave "userId:businessId".
     *
     * @var array<string, BranchAccessSnapshot>
     */
    private array $cache = [];

    /**
     * Devuelve el snapshot del usuario para el negocio.
     */
    public function resolveFor(User $user, string $businessId): BranchAccessSnapshot
    {
        $key = $user->getKey().':'.$businessId;

        if (array_key_exists($key, $this->cache)) {
            return $this->cache[$key];
        }

        $this->cache[$key] = $this->build($user, $businessId);

        return $this->cache[$key];
    }

    /**
     * Invalida el cache. Sin argumentos, borra todo. Con businessId,
     * borra las entradas de ese negocio para todos los users cacheados.
     */
    public function forget(?string $businessId = null): void
    {
        if ($businessId === null) {
            $this->cache = [];

            return;
        }

        foreach (array_keys($this->cache) as $key) {
            if (str_ends_with($key, ':'.$businessId)) {
                unset($this->cache[$key]);
            }
        }
    }

    /**
     * Cuenta entradas en cache (para tests y monitoreo).
     */
    public function cacheSize(): int
    {
        return count($this->cache);
    }

    private function build(User $user, string $businessId): BranchAccessSnapshot
    {
        /** @var ?Business $business */
        $business = Business::query()->find($businessId);
        if (! $business instanceof Business) {
            throw new AuthorizationException('No se pudo verificar el negocio solicitado.');
        }

        $featureEnabled = $business->hasFeature('strict_branch_scope');

        $isOwner = (string) ($business->owner_id ?? '') === (string) $user->getKey();
        $isMasterAdmin = (bool) $user->is_master_admin;

        if (! $isOwner && ! $isMasterAdmin && ! $user->canAccessTenant($business)) {
            throw new AuthorizationException('El usuario no tiene acceso al negocio solicitado.');
        }

        $pivot = null;
        $role = null;
        $branchId = null;

        if (! $isOwner && ! $isMasterAdmin) {
            $pivot = $user->businesses()
                ->where('businesses.id', $businessId)
                ->first()
                ?->pivot;

            if ($pivot) {
                $role = $pivot->role === 'manager' ? 'supervisor' : $pivot->role;
                $branchId = $pivot->branch_id ?? null;
            }
        }

        $isAdmin = $isOwner || $isMasterAdmin || in_array($role, self::ADMIN_ROLES, true);
        $assignedBranch = $branchId !== null && $branchId !== '' ? (string) $branchId : null;
        $canSwitch = $isOwner || $isMasterAdmin || ($isAdmin && $assignedBranch === null);

        if ($featureEnabled && ! $isAdmin && $assignedBranch === null) {
            throw new AuthorizationException('El usuario no tiene una sucursal asignada.');
        }

        $viewBranchId = null;
        $sessionBranch = session(\App\Services\AuthContextResolver::SESSION_BRANCH_ID);
        if (is_string($sessionBranch) && $sessionBranch !== '') {
            $viewBranchId = $sessionBranch;
        }

        if (! $canSwitch && $assignedBranch) {
            $viewBranchId = $assignedBranch;
        }

        foreach (array_filter([$assignedBranch, $viewBranchId]) as $branchIdToValidate) {
            $branchExists = \App\Models\Branch::withoutBusinessScope()
                ->where('business_id', $businessId)
                ->whereKey($branchIdToValidate)
                ->where('is_active', true)
                ->exists();

            if (! $branchExists) {
                throw new AuthorizationException('La sucursal solicitada no pertenece al negocio o está inactiva.');
            }
        }

        return new BranchAccessSnapshot(
            businessId: $businessId,
            featureEnabled: $featureEnabled,
            isAdmin: $isAdmin,
            role: $isOwner ? 'owner' : ($isMasterAdmin ? 'master_admin' : $role),
            branchId: $assignedBranch,
            viewBranchId: $viewBranchId,
            canSwitchBranches: $canSwitch,
        );
    }
}
