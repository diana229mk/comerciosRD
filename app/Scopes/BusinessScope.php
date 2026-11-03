<?php

namespace App\Scopes;

use App\Support\TenantContext;
use Filament\Facades\Filament;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Scope;
use RuntimeException;

/**
 * Global Scope reforzado de aislamiento multi-tenant.
 *
 * Cadena de resolución del business_id:
 *  1) TenantContext explícito verificado — operaciones internas delimitadas
 *  2) Filament::getTenant()               — contexto del panel
 *  3) app('currentBusinessId')            — bind del middleware
 *  4) session('current_business_id')      — fallback API / HTTP
 *  5) auth()->user()->current_business_id — último recurso
 *
 * En producción, si hay usuario autenticado (no master) y NO hay tenant
 * resuelto, se lanza excepción (fail-closed). Nunca se deja pasar un
 * query sin filtro por business_id.
 */
class BusinessScope implements Scope
{
    public const NAME = 'business';

    public function apply(Builder $builder, Model $model): void
    {
        $businessId = $this->resolveBusinessId();

        // Consola / seeders / jobs sin contexto: no filtrar aquí
        // (los jobs de tenant DEBEN setear el business_id explícitamente).
        if ($businessId === null) {
            if ($this->mustEnforce()) {
                throw new RuntimeException(
                    'BusinessScope: no se resolvió business_id con usuario autenticado. '
                    .'Aislamiento multi-tenant en modo fail-closed.'
                );
            }

            return;
        }

        $column = $model->getTable().'.business_id';

        $builder->where($column, $businessId);
    }

    /**
     * Resolución del tenant actual (misma prioridad que BelongsToBusiness).
     */
    protected function resolveBusinessId(): ?string
    {
        if (app()->bound(TenantContext::EXPLICIT_BUSINESS_ID)) {
            $explicitBusinessId = app(TenantContext::EXPLICIT_BUSINESS_ID);
            if ($explicitBusinessId !== null && $explicitBusinessId !== '') {
                return (string) $explicitBusinessId;
            }
        }

        // 1) Tenant activo de Filament
        if (class_exists(Filament::class)) {
            try {
                $tenant = Filament::getTenant();
                if ($tenant !== null) {
                    $key = $tenant->getKey();
                    if ($key !== null && $key !== '') {
                        return (string) $key;
                    }
                }
            } catch (\Throwable) {
                // Filament aún no bootstrapped (CLI, early middleware)
            }
        }

        // 2) Binding del container (EnsureTenantContext)
        if (app()->bound('currentBusinessId')) {
            $bound = app('currentBusinessId');
            if ($bound !== null && $bound !== '') {
                return (string) $bound;
            }
        }

        // 3) Sesión
        $fromSession = session('current_business_id');
        if ($fromSession !== null && $fromSession !== '') {
            return (string) $fromSession;
        }

        // 4) Usuario autenticado (último negocio activo)
        $user = auth()->user();
        if ($user !== null && ! empty($user->current_business_id)) {
            return (string) $user->current_business_id;
        }

        return null;
    }

    /**
     * ¿Debemos exigir tenant? Sí en web autenticado (excepto master/console).
     */
    protected function mustEnforce(): bool
    {
        if (app()->runningInConsole() && ! app()->runningUnitTests()) {
            return false;
        }

        if (! auth()->check()) {
            return false;
        }

        $user = auth()->user();
        if (isset($user->is_master_admin) && $user->is_master_admin) {
            return false;
        }

        // Siempre en producción; en local/testing si TENANCY_ENFORCE_SCOPE=true
        return app()->environment('production')
            || (bool) config('tenancy.enforce_scope', true);
    }
}
