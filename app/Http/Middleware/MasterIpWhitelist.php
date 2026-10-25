<?php

namespace App\Http\Middleware;

use App\Models\MasterAuditLog;
use App\Services\SecurityAlertService;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Whitelist de IPs para el Panel Master.
 *
 * - Desactivado por defecto (evita bloquearte si no configuraste IPs).
 * - Actívalo con SECURITY_MASTER_IP_WHITELIST_ENABLED=true
 * - Lista: SECURITY_MASTER_ALLOWED_IPS=1.2.3.4,5.6.7.8
 * - Siempre incluye localhost (127.0.0.1 / ::1) para desarrollo.
 */
class MasterIpWhitelist
{
    public function handle(Request $request, Closure $next): Response
    {
        // Solo aplica si la whitelist está habilitada
        if (! config('security.master_ip_whitelist_enabled', false)) {
            return $next($request);
        }

        $userIp = (string) $request->ip();
        $allowed = $this->allowedIps();

        if (in_array($userIp, $allowed, true)) {
            return $next($request);
        }

        $user = $request->user();

        app(SecurityAlertService::class)->masterPanelUnauthorizedIp(
            $userIp,
            $user?->getKey() ? (string) $user->getKey() : null
        );

        if ($user) {
            MasterAuditLog::log(
                'master_ip_denied',
                $user,
                [],
                ['ip' => $userIp, 'allowed' => $allowed]
            );
        }

        abort(403, 'Acceso denegado. Tu dirección IP no está autorizada para acceder al Panel Master.');
    }

    /**
     * @return list<string>
     */
    protected function allowedIps(): array
    {
        $configured = config('security.master_allowed_ips', []);

        if (! is_array($configured)) {
            $configured = [];
        }

        // Localhost siempre permitido (dev / SSH tunnel)
        $defaults = ['127.0.0.1', '::1'];

        return array_values(array_unique(array_filter(array_merge($defaults, $configured))));
    }
}
