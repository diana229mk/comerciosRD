<?php

namespace App\Http\Middleware;

use App\Models\Business;
use Closure;
use Filament\Facades\Filament;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

/**
 * Verifica que el plan / tipo del negocio permita una feature.
 *
 * Uso: ->middleware('business.feature:receivables')
 *      ->middleware('business.feature:expiry_tracking')
 */
class CheckBusinessFeature
{
    public function handle(Request $request, Closure $next, string $feature): Response
    {
        $business = $this->resolveBusiness();

        if (! $business) {
            abort(403, 'No hay negocio activo.');
        }

        if (! $this->featureEnabled($business, $feature)) {
            // 404 para no revelar features del plan a atacantes
            throw new NotFoundHttpException('Funcionalidad no disponible.');
        }

        return $next($request);
    }

    protected function resolveBusiness(): ?Business
    {
        try {
            $tenant = Filament::getTenant();
            if ($tenant instanceof Business) {
                return $tenant;
            }
        } catch (\Throwable) {
        }

        if (app()->bound('currentBusiness')) {
            $b = app('currentBusiness');
            if ($b instanceof Business) {
                return $b;
            }
        }

        return null;
    }

    public static function featureEnabled(Business $business, string $feature): bool
    {
        // Override explícito en features JSON del negocio
        $override = data_get($business->features, $feature);
        if ($override !== null) {
            return (bool) $override;
        }

        $map = config('security.features', []);
        $rule = $map[$feature] ?? false;

        if (is_bool($rule)) {
            return $rule;
        }

        if (is_array($rule)) {
            $type = $business->type?->value ?? (string) $business->type;

            return in_array($type, $rule, true);
        }

        return false;
    }
}
