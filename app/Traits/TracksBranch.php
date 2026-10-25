<?php

namespace App\Traits;

use App\Models\Branch;
use App\Services\AuthContextResolver;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Rellena automáticamente branch_id al crear un modelo, si no vino explícito.
 *
 * FASE S2 — Persistir dato, no filtrar todavía.
 *
 * Orden de resolución:
 *  1) Si el modelo ya trae branch_id → respetar.
 *  2) session('current_business_id') === business_id del modelo →
 *     leer session('current_branch_id').
 *  3) Si no, deja null. NUNCA lanza.
 *
 * NO se agrega global scope. Los reportes siguen viendo todo el negocio.
 * NO se cambia branch_id al editar (inmutable como business_id).
 */
trait TracksBranch
{
    protected static function bootTracksBranch(): void
    {
        static::creating(function (Model $model): void {
            $current = $model->getAttribute('branch_id');
            if ($current !== null && $current !== '') {
                return;
            }

            $branchId = static::resolveCurrentBranchId($model);
            if ($branchId !== null) {
                $model->setAttribute('branch_id', $branchId);
            }
        });

        // Inmutabilidad: branch_id se fija al crear, no se cambia por update
        // (evita "mover" ventas o caja de sucursal por error).
        static::updating(function (Model $model): void {
            if ($model->isDirty('branch_id')) {
                $original = $model->getOriginal('branch_id');
                if ($original !== null && $original !== '') {
                    $model->setAttribute('branch_id', $original);
                }
            }
        });
    }

    public function branch(): BelongsTo
    {
        return $this->belongsTo(Branch::class);
    }

    protected static function resolveCurrentBranchId(Model $model): ?string
    {
        try {
            $modelBusinessId = $model->getAttribute('business_id');
            $sessionBusinessId = session(AuthContextResolver::SESSION_BUSINESS_ID);

            // Sólo confiar en la sesión si es el mismo negocio del modelo.
            if ($modelBusinessId !== null
                && $sessionBusinessId !== null
                && (string) $modelBusinessId !== (string) $sessionBusinessId
            ) {
                return null;
            }

            $branchId = session(AuthContextResolver::SESSION_BRANCH_ID);

            if ($branchId !== null && $branchId !== '') {
                return (string) $branchId;
            }
        } catch (\Throwable) {
            // Sin sesión (jobs, tinker, tests): sigue null.
        }

        return null;
    }
}
