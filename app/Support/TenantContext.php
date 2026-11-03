<?php

namespace App\Support;

use App\Models\Business;
use RuntimeException;

/**
 * Aplica / restaura el tenant en jobs y comandos.
 *
 * El Global Scope se apaga en consola. Los jobs de un comercio DEBEN
 * pasar por aquí para no filtrar cruzado ni olvidar business_id.
 */
final class TenantContext
{
    // Scoped override for trusted operations targeting a verified business.
    public const EXPLICIT_BUSINESS_ID = 'explicitBusinessId';

    public static function apply(string $businessId): Business
    {
        $business = Business::query()->find($businessId);

        if (! $business) {
            throw new RuntimeException("TenantContext: negocio {$businessId} no existe.");
        }

        app()->instance('currentBusinessId', (string) $businessId);
        app()->instance('currentBusiness', $business);

        return $business;
    }

    /**
     * @template T
     * @param  callable(Business): T  $callback
     * @return T
     */
    public static function run(string $businessId, callable $callback): mixed
    {
        $previousId = app()->bound('currentBusinessId') ? app('currentBusinessId') : null;
        $previous = app()->bound('currentBusiness') ? app('currentBusiness') : null;
        $hadExplicitBusinessId = app()->bound(self::EXPLICIT_BUSINESS_ID);
        $previousExplicitBusinessId = $hadExplicitBusinessId
            ? app(self::EXPLICIT_BUSINESS_ID)
            : null;

        try {
            $business = self::apply($businessId);
            app()->instance(self::EXPLICIT_BUSINESS_ID, (string) $business->getKey());

            return $callback($business);
        } finally {
            if ($previousId !== null) {
                app()->instance('currentBusinessId', $previousId);
            } else {
                app()->forgetInstance('currentBusinessId');
            }

            if ($previous !== null) {
                app()->instance('currentBusiness', $previous);
            } else {
                app()->forgetInstance('currentBusiness');
            }

            if ($hadExplicitBusinessId) {
                app()->instance(self::EXPLICIT_BUSINESS_ID, $previousExplicitBusinessId);
            } else {
                app()->forgetInstance(self::EXPLICIT_BUSINESS_ID);
            }
        }
    }
}
