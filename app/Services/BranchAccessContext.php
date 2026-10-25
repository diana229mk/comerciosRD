<?php

namespace App\Services;

use App\Models\Business;
use App\Models\User;

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
     * @var array<string, BranchAccessSnapshot|false>
     */
    private array $cache = [];

    /**
     * Devuelve el snapshot para el (user, business) dado, o null si no aplica
     * ninguna guarda (usuario no autenticado, negocio sin la feature, etc).
     *
     * NUNCA lanza. Falla-seguro devolviendo null (no filtra nada).
     */
    public function resolveFor(User $user, string $businessId): ?BranchAccessSnapshot
    {
        $key = $user->getKey().':'.$businessId;

        if (array_key_exists($key, $this->cache)) {
            return $this->cache[$key] ?: null;
        }

        try {
            $snapshot = $this->build($user, $businessId);
        } catch (\Throwable) {
            $snapshot = null;
        }

        $this->cache[$key] = $snapshot ?? false;

        return $snapshot;
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

    private function build(User $user, string $businessId): ?BranchAccessSnapshot
    {
        /** @var ?Business $business */
        $business = Business::query()->find($businessId);
        if (! $business instanceof Business) {
            return null;
        }

        $featureEnabled = $business->hasFeature('strict_branch_scope');

        $isOwner = (string) ($business->owner_id ?? '') === (string) $user->getKey();

        $pivot = null;
        $role = null;
        $branchId = null;

        if (! $isOwner) {
            $pivot = $user->businesses()
                ->where('businesses.id', $businessId)
                ->first()
                ?->pivot;

            if ($pivot) {
                $role = $pivot->role === 'manager' ? 'supervisor' : $pivot->role;
                $branchId = $pivot->branch_id ?? null;
            }
        }

        $isAdmin = $isOwner || in_array($role, self::ADMIN_ROLES, true);
        $assignedBranch = $branchId !== null && $branchId !== '' ? (string) $branchId : null;
        $canSwitch = $isOwner || ($isAdmin && $assignedBranch === null);

        $viewBranchId = null;
        $sessionBranch = session(\App\Services\AuthContextResolver::SESSION_BRANCH_ID);
        if (is_string($sessionBranch) && $sessionBranch !== '') {
            $viewBranchId = $sessionBranch;
        }

        if (! $canSwitch && $assignedBranch) {
            $viewBranchId = $assignedBranch;
        }

        return new BranchAccessSnapshot(
            businessId: $businessId,
            featureEnabled: $featureEnabled,
            isAdmin: $isAdmin,
            role: $isOwner ? 'owner' : $role,
            branchId: $assignedBranch,
            viewBranchId: $viewBranchId,
            canSwitchBranches: $canSwitch,
        );
    }
}
