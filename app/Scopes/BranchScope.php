<?php

namespace App\Scopes;

use App\Models\StockCount;
use App\Models\StockCountItem;
use App\Services\AuthContextResolver;
use App\Services\BranchAccessContext;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Scope;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Session;

/**
 * FASE S4a — Global scope de aislamiento por sucursal.
 *
 * Opt-in por negocio via feature flag `strict_branch_scope`.
 *
 * Aplica SOLO si TODAS las condiciones se cumplen:
 *   1. Hay usuario autenticado.
 *   2. Hay negocio actual resuelto en sesión y coincide con el del modelo.
 *   3. Ese negocio tiene la feature `strict_branch_scope` habilitada.
 *   4. El usuario NO es owner/admin/supervisor/manager en ese negocio.
 *   5. El usuario tiene una sucursal asignada (pivot user_business.branch_id).
 *
 * Cuando aplica, restringe por sucursal. Los conteos de inventario no admiten
 * branch_id NULL porque contienen snapshots de cantidades y costos.
 *
 * FASE H1: la resolución (business, features, pivot) se lee de
 * BranchAccessContext (cache request-scoped) para evitar N+1 sobre
 * `businesses` y `user_business` en cada query filtrada.
 *
 * Cero cambios si el flag está apagado (default).
 */
final class BranchScope implements Scope
{
    public function apply(Builder $builder, Model $model): void
    {
        $branchId = $this->resolveBranchId($model);

        if ($branchId === null) {
            return;
        }

        $table = $model->getTable();
        $allowUnassignedBranch = ! ($model instanceof StockCount)
            && ! ($model instanceof StockCountItem);

        $builder->where(function (Builder $q) use ($branchId, $table, $allowUnassignedBranch): void {
            $q->where("{$table}.branch_id", $branchId)
                ->when($allowUnassignedBranch, fn (Builder $q) => $q->orWhereNull("{$table}.branch_id"));
        });
    }

    /**
     * Devuelve el branch_id a filtrar, o null si el scope NO debe aplicar.
     */
    private function resolveBranchId(Model $model): ?string
    {
        if (! Auth::check()) {
            return null;
        }

        /** @var \App\Models\User $user */
        $user = Auth::user();
        $sessionBusinessId = Session::get(AuthContextResolver::SESSION_BUSINESS_ID)
            ?: (app()->bound('currentBusinessId') ? app('currentBusinessId') : null)
            ?: $user->current_business_id;

        if (! $sessionBusinessId) {
            if ($user->is_master_admin) {
                return null;
            }

            throw new AuthorizationException('No se pudo verificar el contexto de sucursal.');
        }

        // El modelo debe pertenecer al mismo negocio que la sesión.
        $modelBusinessId = $model->getAttribute('business_id');
        if ($modelBusinessId !== null && (string) $modelBusinessId !== (string) $sessionBusinessId) {
            throw new AuthorizationException('El registro no pertenece al negocio activo.');
        }

        $snapshot = app(BranchAccessContext::class)->resolveFor($user, (string) $sessionBusinessId);

        if (! $snapshot->shouldRestrictReads()) {
            return null;
        }

        return $snapshot->effectiveBranchId();
    }
}
