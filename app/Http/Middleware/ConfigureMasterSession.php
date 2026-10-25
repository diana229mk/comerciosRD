<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Acorta el lifetime de sesión solo en el Panel Master (antes de StartSession).
 *
 * Filament 3 no expone sessionLifetime(); se sobrescribe session.lifetime
 * solo para requests del panel master.
 */
class ConfigureMasterSession
{
    public function handle(Request $request, Closure $next): Response
    {
        $minutes = (int) (
            config('session.master_lifetime')
            ?: config('security.master_session_lifetime', 120)
        );

        if ($minutes > 0) {
            config([
                'session.lifetime' => $minutes,
            ]);

            // Solo en producción: caducar al cerrar el navegador
            if (app()->environment('production')) {
                config(['session.expire_on_close' => true]);
            }
        }

        return $next($request);
    }
}
