<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Symfony\Component\HttpFoundation\Response;

/**
 * Solo obliga a Master Admin a configurar 2FA si está marcado como obligatorio.
 * Por defecto el 2FA es opcional: se puede activar cuando el usuario quiera.
 */
class EnsureTwoFactorIsEnabled
{
    public function handle(Request $request, Closure $next): Response
    {
        $user = Auth::user();

        if (! $user || ! ($user->is_master_admin ?? false)) {
            return $next($request);
        }

        // Feature apagada o master no obligatorio → no forzar setup
        if (! config('security.two_factor.enabled', true)
            || ! config('security.two_factor.require_master', false)
        ) {
            return $next($request);
        }

        // Ya configurado: dejar pasar (el challenge de sesión lo hace EnsureTwoFactorVerified)
        if ($user->hasTwoFactorEnabled()) {
            return $next($request);
        }

        // Evitar bucle en la propia página de setup, logout y Livewire del setup
        if ($this->isExempt($request)) {
            return $next($request);
        }

        return new RedirectResponse(route('filament.master.pages.setup-2fa'));
    }

    protected function isExempt(Request $request): bool
    {
        if ($request->routeIs('filament.master.pages.setup-2fa')
            || $request->routeIs('filament.master.auth.logout')
            || $request->routeIs('filament.*.auth.logout')
        ) {
            return true;
        }

        if ($request->is('master/setup-2fa')
            || $request->is('master/setup-2fa/*')
            || $request->is('master/logout')
        ) {
            return true;
        }

        // Livewire del formulario de setup
        if ($request->is('livewire/*')) {
            $referer = (string) $request->headers->get('referer', '');

            return str_contains($referer, '/setup-2fa')
                || str_contains($referer, '/challenge-2fa');
        }

        return false;
    }
}
