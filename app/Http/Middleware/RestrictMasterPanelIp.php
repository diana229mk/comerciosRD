<?php

namespace App\Http\Middleware;

use App\Services\SecurityAlertService;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Restringe Panel Master a IPs autorizadas (si se configuran).
 */
class RestrictMasterPanelIp
{
    public function handle(Request $request, Closure $next): Response
    {
        $allowed = config('security.master_allowed_ips', []);

        if ($allowed === [] || $allowed === null) {
            return $next($request);
        }

        $ip = (string) $request->ip();

        if (! in_array($ip, $allowed, true)) {
            app(SecurityAlertService::class)->masterPanelUnauthorizedIp(
                $ip,
                $request->user()?->getKey() ? (string) $request->user()->getKey() : null
            );

            abort(404);
        }

        return $next($request);
    }
}
