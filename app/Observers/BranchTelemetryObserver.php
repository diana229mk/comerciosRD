<?php

namespace App\Observers;

use App\Models\Branch;
use App\Models\Business;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Log;

/**
 * FASE H5 · Telemetría defensiva para operaciones sin sucursal.
 *
 * Vigila que operaciones nuevas (Sale, Order, Expense, CashSession, Purchase)
 * en negocios que YA tienen sucursales activas NO estén naciendo con
 * branch_id = NULL. Ese caso significa que el trait TracksBranch fue
 * bypasseado (saveQuietly / DB::table / import) y el registro entra al
 * "fondo común" visible por todos — contamina reportes por sucursal.
 *
 * Registra warning en el log; NO bloquea la escritura. Diseñado para
 * detección en producción sin romper flujos legítimos.
 *
 * Se registra manualmente en AppServiceProvider::boot para los 5 modelos
 * operativos que llevan TracksBranch. No se aplica a modelos de inventario
 * porque tienen su propia lógica FEFO.
 */
class BranchTelemetryObserver
{
    /**
     * Cache request-scoped: business_id => hasBranches (evita N+1 en imports).
     *
     * @var array<string, bool>
     */
    private static array $hasBranchesCache = [];

    public function created(Model $model): void
    {
        $this->checkBranchAssignment($model);
    }

    private function checkBranchAssignment(Model $model): void
    {
        try {
            $businessId = $model->getAttribute('business_id');
            $branchId = $model->getAttribute('branch_id');

            if (! $businessId) {
                return;
            }

            if ($branchId !== null && $branchId !== '') {
                return;
            }

            if (! $this->businessHasBranches((string) $businessId)) {
                return;
            }

            Log::warning('branch_id null en modelo con sucursales activas', [
                'model' => $model::class,
                'id' => $model->getKey(),
                'business_id' => (string) $businessId,
                'user_id' => Auth::id(),
                'source' => $this->detectSource(),
            ]);
        } catch (\Throwable $e) {
            // Nunca romper el flujo. Log silencioso.
            Log::debug('BranchTelemetryObserver falló silenciosamente', [
                'error' => $e->getMessage(),
            ]);
        }
    }

    private function businessHasBranches(string $businessId): bool
    {
        if (isset(self::$hasBranchesCache[$businessId])) {
            return self::$hasBranchesCache[$businessId];
        }

        try {
            $has = Branch::query()
                ->where('business_id', $businessId)
                ->where('is_active', true)
                ->exists();
        } catch (\Throwable) {
            $has = false;
        }

        self::$hasBranchesCache[$businessId] = $has;

        return $has;
    }

    /**
     * Heurística: detecta si el warning viene de HTTP, CLI o job.
     */
    private function detectSource(): string
    {
        if (app()->runningInConsole()) {
            return 'cli_or_job';
        }

        try {
            $route = request()->route();

            return $route?->getName() ?? 'http_unnamed';
        } catch (\Throwable) {
            return 'unknown';
        }
    }
}
