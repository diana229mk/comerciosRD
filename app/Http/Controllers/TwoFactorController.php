<?php

namespace App\Http\Controllers;

use App\Http\Middleware\EnsureTwoFactorVerified;
use App\Services\AuditLogger;
use App\Services\TwoFactorService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

class TwoFactorController extends Controller
{
    public function showSetup(Request $request, TwoFactorService $twoFactor): View|RedirectResponse
    {
        $user = $request->user();
        abort_unless($user, 403);

        if ($twoFactor->isEnabled($user)) {
            return new RedirectResponse(route('two-factor.challenge'));
        }

        $secret = $request->session()->get('security.2fa_setup_secret')
            ?? $twoFactor->generateSecret();

        $request->session()->put('security.2fa_setup_secret', $secret);

        return view('security.two-factor-setup', [
            'qrSvg' => $twoFactor->qrCodeSvg($user, $secret),
            'secret' => $secret,
            'recoveryCodes' => [],
        ]);
    }

    public function enable(Request $request, TwoFactorService $twoFactor): RedirectResponse|View
    {
        $request->validate([
            'code' => ['required', 'string'],
        ]);

        $user = $request->user();
        $secret = $request->session()->get('security.2fa_setup_secret');

        if (! $secret || ! $twoFactor->enable($user, $secret, (string) $request->input('code'))) {
            return back()->withErrors(['code' => 'Código inválido. Intenta de nuevo.']);
        }

        $request->session()->forget('security.2fa_setup_secret');
        $request->session()->put(EnsureTwoFactorVerified::SESSION_PASSED, true);

        app(AuditLogger::class)->info(
            'security.2fa_enabled',
            '2FA activado para '.$user->email,
            userId: (string) $user->getKey()
        );

        return view('security.two-factor-setup', [
            'qrSvg' => null,
            'secret' => null,
            'recoveryCodes' => $twoFactor->recoveryCodes($user)->all(),
        ]);
    }

    public function showChallenge(Request $request, TwoFactorService $twoFactor): View|RedirectResponse
    {
        abort_unless($request->user(), 403);

        if ($request->session()->get(EnsureTwoFactorVerified::SESSION_PASSED)) {
            return new RedirectResponse(url($this->homePath($request->user())));
        }

        if (! $twoFactor->isEnabled($request->user())) {
            return new RedirectResponse(route('two-factor.setup'));
        }

        return view('security.two-factor-challenge');
    }

    public function verify(Request $request, TwoFactorService $twoFactor): RedirectResponse
    {
        $request->validate([
            'code' => ['required', 'string'],
        ]);

        $user = $request->user();

        if (! $twoFactor->verify($user, (string) $request->input('code'))) {
            app(AuditLogger::class)->warning(
                'security.2fa_failed',
                'Código 2FA inválido',
                userId: (string) $user->getKey()
            );

            return back()->withErrors(['code' => 'Código incorrecto.']);
        }

        $request->session()->put(EnsureTwoFactorVerified::SESSION_PASSED, true);

        app(AuditLogger::class)->info(
            'security.2fa_passed',
            '2FA verificado en sesión',
            userId: (string) $user->getKey()
        );

        $intended = $request->session()->pull('url.intended', $this->homePath($user));

        return new RedirectResponse(url($intended));
    }

    /**
     * Destino post-2FA: master admins van a /master; el resto a /admin.
     */
    protected function homePath($user): string
    {
        if ($user && ($user->is_master_admin ?? false)) {
            return '/master';
        }

        return '/admin';
    }
}
