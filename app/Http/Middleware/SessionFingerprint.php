<?php

namespace App\Http\Middleware;

use App\Services\SecurityAlertService;
use Closure;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Symfony\Component\HttpFoundation\Response;

/**
 * Detecta posible secuestro de sesión comparando fingerprint
 * (User-Agent y opcionalmente IP) guardado al login.
 */
class SessionFingerprint
{
    public const SESSION_KEY = 'security.session_fingerprint';

    public function handle(Request $request, Closure $next): Response
    {
        if (! config('security.session_fingerprint.enabled', true)) {
            return $next($request);
        }

        if (! Auth::check()) {
            return $next($request);
        }

        $current = $this->fingerprint($request);
        $stored = session(self::SESSION_KEY);

        if ($stored === null) {
            session([self::SESSION_KEY => $current]);

            return $next($request);
        }

        if (! hash_equals((string) $stored, $current)) {
            $userId = (string) Auth::id();

            app(SecurityAlertService::class)->sessionHijackSuspected($userId, [
                'stored' => substr((string) $stored, 0, 16).'…',
                'current' => substr($current, 0, 16).'…',
                'ip' => $request->ip(),
            ]);

            Auth::logout();
            $request->session()->invalidate();
            $request->session()->regenerateToken();

            if ($request->hasSession()) {
                $request->session()->put('url.intended', $request->fullUrl());
                $request->session()->flash('status', 'Sesión cerrada por seguridad. Vuelve a iniciar sesión.');
            }

            return new RedirectResponse(url('/admin/login'));
        }

        return $next($request);
    }

    public static function store(Request $request): void
    {
        session([self::SESSION_KEY => (new self)->fingerprint($request)]);
    }

    protected function fingerprint(Request $request): string
    {
        $parts = [];

        if (config('security.session_fingerprint.bind_user_agent', true)) {
            $parts[] = (string) $request->userAgent();
        }

        if (config('security.session_fingerprint.bind_ip', false)) {
            $parts[] = (string) $request->ip();
        }

        // NO incluir session id: al regenerar la cookie el id cambia y invalidaba
        // la sesión legítima (síntoma: "expiró" / 419 justo después del login).
        $parts[] = (string) Auth::id();

        return hash('sha256', implode('|', $parts));
    }
}
