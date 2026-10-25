<?php

namespace App\Http\Middleware;

use App\Models\Branch;
use App\Services\AuthContextResolver;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * FASE H5 · Verifica que la sucursal activa en sesión esté ACTIVA (o el pivot
 * user_business.branch_id apunte a una sucursal activa).
 *
 * Casos que ataca:
 *  - Dueño desactiva la sucursal donde opera un cajero → el cajero, al
 *    siguiente request, pierde branch en sesión y se le muestra un aviso.
 *  - Sucursal soft-deleted → mismo trato.
 *
 * Comportamiento:
 *  - Si el branch en sesión está inactiva/eliminada → forget branch, permitir
 *    que AuthContextResolver la reasigne desde pivot o mainBranch en el
 *    próximo `apply()` del middleware EnsureTenantContext.
 *  - No cierra sesión al usuario. Si su pivot apunta a una sucursal inactiva
 *    Y no hay mainBranch, queda con branch = null (fallback seguro).
 *
 * NO lanza. NO redirige. Es un limpiador silencioso de contexto stale.
 * El middleware EnsureTenantContext corre DESPUÉS y reasigna correctamente.
 */
class CheckBranchActivation
{
    public function handle(Request $request, Closure $next): Response
    {
        try {
            $branchId = session(AuthContextResolver::SESSION_BRANCH_ID);

            if (! is_string($branchId) || $branchId === '') {
                return $next($request);
            }

            $exists = Branch::query()
                ->whereKey($branchId)
                ->where('is_active', true)
                ->exists();

            if (! $exists) {
                session()->forget(AuthContextResolver::SESSION_BRANCH_ID);
                session()->flash(
                    'branch_deactivated',
                    'La sucursal donde estabas operando fue desactivada. Se seleccionó otra automáticamente.'
                );
            }
        } catch (\Throwable) {
            // Silencioso: no romper el flujo por telemetría.
        }

        return $next($request);
    }
}
