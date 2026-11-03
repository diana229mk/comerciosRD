<?php

namespace App\Policies;

use App\Models\StockCount;
use App\Models\StockCountItem;
use App\Models\User;
use App\Traits\ScopedByBranch;
use Illuminate\Auth\Access\HandlesAuthorization;
use Illuminate\Auth\Access\Response;
use Illuminate\Database\Eloquent\Model;

abstract class TenantPolicy
{
    use HandlesAuthorization;

    /** Roles con visión completa del negocio (bypass del branch guard). */
    protected const ADMIN_ROLES = ['owner', 'admin', 'supervisor', 'manager'];

    /**
     * Verifica que el registro pertenezca al negocio actual.
     * Si no pertenece → aborta con 404 (nunca 403).
     */
    protected function denyIfForeignTenant(Model $record): void
    {
        if (method_exists($record, 'assertBelongsToCurrentBusiness')) {
            $record->assertBelongsToCurrentBusiness();
        }
    }

    /**
     * Verifica que el usuario pertenezca al negocio del registro.
     */
    protected function userBelongsToRecordBusiness(User $user, Model $record): bool
    {
        return $user->businesses()
            ->where('businesses.id', $record->business_id)
            ->wherePivot('is_active', true)
            ->exists();
    }

    /**
     * FASE S4c — Guarda de sucursal en escrituras.
     *
     * Devuelve `Response::denyAsNotFound()` (para no filtrar existencia) si:
     *   - El modelo participa del scope de sucursal (usa ScopedByBranch).
     *   - El negocio tiene la feature `strict_branch_scope`.
     *   - El usuario NO es owner/admin/supervisor/manager.
     *   - El usuario tiene una sucursal asignada en el pivot.
     *   - El record tiene branch_id no-nulo distinto al del usuario.
     *
     * Devuelve `null` si el guard NO aplica (comportamiento actual).
     *
     * NO se aplica a `view()` — las lecturas ya las limita el global scope
     * BranchScope (S4a). Este guard es para prevenir update/delete/cancel/etc.
     * de un cajero sobre un registro de otra sucursal si logró obtener el ID.
     *
     * FASE H1: consulta el snapshot cacheado en BranchAccessContext, evitando
     * queries repetidas a `businesses` y `user_business` en cada policy check.
     */
    protected function denyIfForeignBranch(User $user, Model $record): ?Response
    {
        if (! in_array(ScopedByBranch::class, class_uses_recursive($record), true)) {
            return null;
        }

        $businessId = $record->getAttribute('business_id');
        if (! $businessId) {
            return null;
        }

        $snapshot = app(\App\Services\BranchAccessContext::class)
            ->resolveFor($user, (string) $businessId);

        if (! $snapshot->shouldRestrictReads()) {
            return null;
        }

        $recordBranchId = $record->getAttribute('branch_id');
        if ($recordBranchId === null || $recordBranchId === '') {
            if ($record instanceof StockCount || $record instanceof StockCountItem) {
                return Response::denyAsNotFound();
            }

            // Otros registros históricos sin sucursal permanecen compartidos.
            return null;
        }

        if ((string) $recordBranchId === (string) $snapshot->branchId) {
            return null;
        }

        return Response::denyAsNotFound();
    }

    // ==================== MÉTODOS BASE ====================

    public function viewAny(User $user): bool
    {
        return true; // El Global Scope ya filtra por tenant
    }

    public function view(User $user, Model $record): Response|bool
    {
        $this->denyIfForeignTenant($record);

        return $this->userBelongsToRecordBusiness($user, $record)
            ? Response::allow()
            : Response::denyAsNotFound();
    }

    public function create(User $user): bool
    {
        return true; // El trait inyecta business_id automáticamente
    }

    public function update(User $user, Model $record): Response|bool
    {
        $this->denyIfForeignTenant($record);

        if ($denied = $this->denyIfForeignBranch($user, $record)) {
            return $denied;
        }

        return $this->userBelongsToRecordBusiness($user, $record)
            ? Response::allow()
            : Response::denyAsNotFound();
    }

    public function delete(User $user, Model $record): Response|bool
    {
        $this->denyIfForeignTenant($record);

        if ($denied = $this->denyIfForeignBranch($user, $record)) {
            return $denied;
        }

        return $this->userBelongsToRecordBusiness($user, $record)
            ? Response::allow()
            : Response::denyAsNotFound();
    }

    public function restore(User $user, Model $record): Response|bool
    {
        $this->denyIfForeignTenant($record);

        if ($denied = $this->denyIfForeignBranch($user, $record)) {
            return $denied;
        }

        return $this->userBelongsToRecordBusiness($user, $record)
            ? Response::allow()
            : Response::denyAsNotFound();
    }

    public function forceDelete(User $user, Model $record): Response|bool
    {
        $this->denyIfForeignTenant($record);

        if ($denied = $this->denyIfForeignBranch($user, $record)) {
            return $denied;
        }

        return $this->userBelongsToRecordBusiness($user, $record)
            ? Response::allow()
            : Response::denyAsNotFound();
    }
}
