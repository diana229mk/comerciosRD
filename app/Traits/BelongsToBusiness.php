<?php

namespace App\Traits;

use App\Models\Business;
use App\Scopes\BusinessScope;
use App\Services\AuditLogger;
use App\Services\SecurityAlertService;
use Filament\Facades\Filament;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Facades\Auth;
use RuntimeException;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

/**
 * Trait obligatorio en TODOS los modelos de datos de un comercio.
 *
 * Capas de aislamiento (fail-closed):
 *  A) Global Scope BusinessScope — filtra business_id en TODO query
 *  B) Forzado de business_id al crear (nunca confía en input del cliente)
 *  C) Inmutabilidad de business_id al editar
 *  D) assertBelongsToCurrentBusiness() — triple validación → 404
 *
 * NUNCA desactivar el Global Scope en código de negocio.
 * Usar withoutBusinessScope() SOLO en panel master / jobs / seeders.
 */
trait BelongsToBusiness
{
    protected static function bootBelongsToBusiness(): void
    {
        // 1) Global Scope reforzado (fail-closed vía BusinessScope)
        static::addGlobalScope(BusinessScope::NAME, new BusinessScope);

        // 2) Al crear: SIEMPRE forzar tenant actual (no confiar en mass-assignment)
        static::creating(function (Model $model): void {
            $businessId = static::resolveCurrentBusinessId();

            if ($businessId !== null) {
                $model->setAttribute('business_id', $businessId);

                return;
            }

            // Solo se permite business_id pre-seteado en CLI/seeders sin contexto
            if (empty($model->getAttribute('business_id'))) {
                throw new RuntimeException(
                    class_basename($model).': no se puede crear sin business_id. '
                    .'Asegúrate de tener un tenant activo.'
                );
            }
        });

        // 3) Bloquear cambio de business_id al editar (restaurar original)
        static::updating(function (Model $model): void {
            if ($model->isDirty('business_id')) {
                $model->setAttribute('business_id', $model->getOriginal('business_id'));
            }
        });

        static::saving(function (Model $model): void {
            if ($model->exists && $model->isDirty('business_id')) {
                $model->setAttribute('business_id', $model->getOriginal('business_id'));
            }
        });
    }

    public function initializeBelongsToBusiness(): void
    {
        // business_id no se expone como fillable
    }

    /**
     * Relación con el tenant.
     */
    public function business(): BelongsTo
    {
        return $this->belongsTo(Business::class);
    }

    /**
     * Obtiene el Business actual (tenant) de forma segura.
     *
     * Orden: Filament tenant → container → session → user.currentBusiness
     */
    public static function getCurrentBusiness(): ?Business
    {
        // 1) Filament tenant activo
        if (class_exists(Filament::class)) {
            try {
                $tenant = Filament::getTenant();
                if ($tenant instanceof Business) {
                    return $tenant;
                }
            } catch (\Throwable) {
            }
        }

        // 2) Binding del middleware EnsureTenantContext
        if (app()->bound('currentBusiness')) {
            $bound = app('currentBusiness');
            if ($bound instanceof Business) {
                return $bound;
            }
        }

        $id = static::resolveCurrentBusinessId();
        if ($id === null) {
            return null;
        }

        // Cache corta en request
        $cacheKey = 'belongs_to_business.model.'.$id;
        if (app()->bound($cacheKey)) {
            return app($cacheKey);
        }

        $business = Business::query()->find($id);
        if ($business) {
            app()->instance($cacheKey, $business);
        }

        return $business;
    }

    /**
     * ID del tenant actual (misma cadena que BusinessScope).
     */
    public static function resolveCurrentBusinessId(): ?string
    {
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
            }
        }

        if (app()->bound('currentBusinessId')) {
            $bound = app('currentBusinessId');
            if ($bound !== null && $bound !== '') {
                return (string) $bound;
            }
        }

        $sessionId = session('current_business_id');
        if ($sessionId !== null && $sessionId !== '') {
            return (string) $sessionId;
        }

        $user = Auth::user();
        if ($user !== null) {
            if (method_exists($user, 'currentBusiness')) {
                $current = $user->currentBusiness;
                if ($current instanceof Business) {
                    return (string) $current->getKey();
                }
            }

            if (! empty($user->current_business_id)) {
                return (string) $user->current_business_id;
            }
        }

        return null;
    }

    /**
     * Verifica que el registro pertenezca al tenant actual.
     * Fallo → 404 (NUNCA 403). No revela existencia de datos ajenos.
     *
     * Triple validación:
     *  1) El registro tiene business_id
     *  2) Hay tenant en contexto
     *  3) Coinciden (hash_equals + cast string para UUIDs)
     */
    public function assertBelongsToCurrentBusiness(): static
    {
        $recordBusinessId = $this->getAttribute('business_id');
        $currentBusinessId = static::resolveCurrentBusinessId();

        $hasRecord = $recordBusinessId !== null && $recordBusinessId !== '';
        $hasContext = $currentBusinessId !== null && $currentBusinessId !== '';
        $matches = $hasRecord
            && $hasContext
            && hash_equals((string) $currentBusinessId, (string) $recordBusinessId);

        if (! $matches) {
            $this->logCrossTenantAttempt(
                $hasRecord ? (string) $recordBusinessId : null,
                $currentBusinessId
            );

            abort(404, 'Registro no encontrado.');
        }

        return $this;
    }

    public function belongsToCurrentBusiness(): bool
    {
        $current = static::resolveCurrentBusinessId();
        $record = $this->getAttribute('business_id');

        if ($current === null || $record === null) {
            return false;
        }

        return hash_equals((string) $current, (string) $record);
    }

    /**
     * Scope local opcional (el Global Scope ya filtra siempre).
     */
    public function scopeWhereBelongsToCurrentBusiness(Builder $query): Builder
    {
        $businessId = static::resolveCurrentBusinessId();

        if ($businessId === null) {
            // Fail-closed: sin tenant → ningún resultado
            return $query->whereRaw('1 = 0');
        }

        return $query->where($query->getModel()->getTable().'.business_id', $businessId);
    }

    /**
     * SOLO master panel / jobs / seeders. NUNCA en Resources de negocio.
     */
    public static function withoutBusinessScope(): Builder
    {
        return static::withoutGlobalScope(BusinessScope::NAME);
    }

    public function scopeForBusiness(Builder $query, string $businessId): Builder
    {
        return $query
            ->withoutGlobalScope(BusinessScope::NAME)
            ->where($query->getModel()->getTable().'.business_id', $businessId);
    }

    protected function logCrossTenantAttempt(?string $recordBusinessId, ?string $currentBusinessId): void
    {
        try {
            $userId = Auth::id() ? (string) Auth::id() : null;

            app(AuditLogger::class)->critical(
                'security.cross_tenant',
                'Intento de acceso cross-tenant bloqueado (404)',
                [
                    'model' => static::class,
                    'record_id' => $this->getKey(),
                    'record_business_id' => $recordBusinessId,
                    'context_business_id' => $currentBusinessId,
                ],
                auditable: $this->exists ? $this : null,
            );

            if ($userId && $recordBusinessId) {
                app(SecurityAlertService::class)->crossTenantAccessAttempt(
                    $userId,
                    $recordBusinessId,
                    static::class
                );
            }
        } catch (\Throwable) {
            // Nunca romper el 404 por fallo de audit
        }
    }
}
