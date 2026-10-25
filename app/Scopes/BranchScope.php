<?php

namespace App\Scopes;

use App\Services\AuthContextResolver;
use App\Services\BranchAccessContext;
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
 * Cuando aplica, restringe:
 *   WHERE branch_id = <user_branch> OR branch_id IS NULL
 *
 * NULL = fondo común (histórico pre-S1). Se mantiene visible para no ocultar
 * datos legítimos que aún no fueron migrados a una sucursal.
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

        $builder->where(function (Builder $q) use ($branchId, $table): void {
            $q->where("{$table}.branch_id", $branchId)
                ->orWhereNull("{$table}.branch_id");
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

        $sessionBusinessId = Session::get(AuthContextResolver::SESSION_BUSINESS_ID);
        if (! $sessionBusinessId) {
            return null;
        }

        // El modelo debe pertenecer al mismo negocio que la sesión.
        $modelBusinessId = $model->getAttribute('business_id');
        if ($modelBusinessId !== null && (string) $modelBusinessId !== (string) $sessionBusinessId) {
            return null;
        }

        /** @var \App\Models\User $user */
        $user = Auth::user();

        $snapshot = app(BranchAccessContext::class)->resolveFor($user, (string) $sessionBusinessId);

        if ($snapshot === null || ! $snapshot->shouldRestrictReads()) {
            return null;
        }

        return $snapshot->effectiveBranchId();
    }
}

