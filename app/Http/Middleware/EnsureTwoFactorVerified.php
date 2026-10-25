<?php

namespace App\Http\Middleware;

use App\Filament\Master\Pages\ChallengeTwoFactor;
use App\Filament\Master\Pages\SetupTwoFactor;
use App\Services\TwoFactorService;
use Closure;
use Filament\Facades\Filament;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * 2FA opcional por defecto.
 *
 * - Si el usuario NO lo tiene activado → no se fuerza (puede entrar sin Authenticator).
 * - Si el usuario SÍ lo activó → pide código en cada sesión.
 * - Solo si required_roles / require_master están en true → se fuerza el setup.
 */
class EnsureTwoFactorVerified
{
    public const SESSION_PASSED = 'security.2fa_passed';

    public function handle(Request $request, Closure $next): Response
    {
        if (! config('security.two_factor.enabled', true)) {
            return $next($request);
        }

        $user = $request->user();
        if (! $user) {
            return $next($request);
        }

        // Rutas de 2FA y logout (admin + master)
        $isTwoFactorPath = $request->routeIs('two-factor.*')
            || $request->routeIs('filament.master.pages.setup-two-factor')
            || $request->routeIs('filament.master.pages.challenge-two-factor')
            || $request->routeIs('filament.master.pages.setup-2fa')
            || $request->routeIs('filament.master.pages.challenge-2fa')
            || $request->is('admin/two-factor/*')
            || $request->is('security/two-factor/*')
            || $request->is('master/setup-2fa')
            || $request->is('master/setup-2fa/*')
            || $request->is('master/challenge-2fa')
            || $request->is('master/challenge-2fa/*');

        $isLogout = $request->routeIs('filament.*.auth.logout')
            || $request->is('admin/logout')
            || $request->is('master/logout');

        // Livewire solo si viene de páginas 2FA (setup/challenge)
        $isLivewireFrom2fa = $request->is('livewire/*') && $this->refererIsTwoFactor($request);

        if ($isTwoFactorPath || $isLogout || $isLivewireFrom2fa) {
            return $next($request);
        }

        $twoFactor = app(TwoFactorService::class);
        $role = null;

        try {
            $tenant = Filament::getTenant();
            if ($tenant) {
                $role = $user->roleInBusiness($tenant->getKey());
            }
        } catch (\Throwable) {
        }

        $panelId = null;
        try {
            $panelId = Filament::getCurrentPanel()?->getId();
        } catch (\Throwable) {
        }

        $required = $twoFactor->isRequiredFor($user, $role);
        $enabled = $twoFactor->isEnabled($user);

        // Opcional: sin 2FA activado y sin política obligatoria → entrar normal
        if (! $required && ! $enabled) {
            return $next($request);
        }

        // Obligatorio pero aún no configurado → setup
        if ($required && ! $enabled) {
            if ($panelId === 'master' || $request->is('master') || $request->is('master/*')) {
                return new RedirectResponse(SetupTwoFactor::getUrl(panel: 'master'));
            }

            return new RedirectResponse(route('two-factor.setup'));
        }

        // Tiene 2FA activado (voluntario u obligatorio) → pedir código por sesión
        if ($enabled && ! $request->session()->get(self::SESSION_PASSED)) {
            if ($panelId === 'master' || $request->is('master') || $request->is('master/*')) {
                return new RedirectResponse(ChallengeTwoFactor::getUrl(panel: 'master'));
            }

            return new RedirectResponse(route('two-factor.challenge'));
        }

        return $next($request);
    }

    protected function refererIsTwoFactor(Request $request): bool
    {
        $referer = (string) $request->headers->get('referer', '');

        if ($referer === '') {
            return false;
        }

        return str_contains($referer, '/setup-2fa')
            || str_contains($referer, '/challenge-2fa')
            || str_contains($referer, '/two-factor')
            || str_contains($referer, '/security/two-factor');
    }
}
