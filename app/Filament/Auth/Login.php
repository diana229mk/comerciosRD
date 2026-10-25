<?php

namespace App\Filament\Auth;

use App\Http\Middleware\SessionFingerprint;
use App\Models\User;
use App\Services\AuthContextResolver;
use App\Services\LoginThrottleService;
use App\Services\MembershipIdentity;
use Illuminate\Support\Facades\Hash;
use DanHarrin\LivewireRateLimiting\Exceptions\TooManyRequestsException;
use Filament\Facades\Filament;
use Filament\Forms\Components\Component;
use Filament\Forms\Components\TextInput;
use Filament\Http\Responses\Auth\Contracts\LoginResponse;
use Filament\Models\Contracts\FilamentUser;
use Filament\Pages\Auth\Login as BaseLogin;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Validation\ValidationException;

/**
 * Login Filament (panel negocio) con:
 *  - RateLimiter "login" (email+IP y IP)
 *  - Escalera LoginThrottleService
 *  - Mensajes genéricos (sin filtrar existencia de usuario / panel)
 */
class Login extends BaseLogin
{
    public function mount(): void
    {
        if (Filament::auth()->check()) {
            $user = Filament::auth()->user();
            $panel = Filament::getCurrentPanel();
            $canAccess = $user instanceof FilamentUser
                && $panel
                && $user->canAccessPanel($panel);

            // Master (u otro panel) no debe dejar una sesión que mate el CSRF
            // al entrar a /admin/login. Logout sin invalidate().
            if (! $canAccess) {
                Filament::auth()->logout();
                parent::mount();

                return;
            }

            $dest = session(AuthContextResolver::SESSION_PANEL_DESTINATION);
            if ((! is_string($dest) || $dest === '') && $user instanceof User) {
                $dest = app(AuthContextResolver::class)->apply($user)['panel_url'] ?? null;
            }
            if (is_string($dest) && $dest !== '') {
                redirect()->to($dest);

                return;
            }
        }

        parent::mount();
    }

    public function authenticate(): ?LoginResponse
    {
        $data = $this->form->getState();
        $email = strtolower(trim((string) ($data['email'] ?? '')));
        $data['email'] = $email;
        $businessSlug = strtolower(trim((string) ($data['business_slug'] ?? '')));
        $ip = (string) request()->ip();
        $throttle = app(LoginThrottleService::class);

        $this->ensureLoginRateLimits($email, $ip);

        if ($throttle->tooManyAttempts($email, $ip)) {
            $seconds = $throttle->availableInSeconds($email, $ip);

            throw ValidationException::withMessages([
                'data.email' => __('Demasiados intentos. Espera :seconds segundos e inténtalo de nuevo.', [
                    'seconds' => max(1, $seconds),
                ]),
            ]);
        }

        try {
            $this->rateLimit(5);
        } catch (TooManyRequestsException $exception) {
            $throttle->recordFailure($email, $ip, request()->userAgent());
            $this->hitLoginCounters($email, $ip);

            throw ValidationException::withMessages([
                'data.email' => __('Demasiados intentos. Intenta en :seconds segundos.', [
                    'seconds' => $exception->secondsUntilAvailable,
                ]),
            ]);
        }

        $user = app(MembershipIdentity::class)->resolveUser(
            $email,
            $businessSlug !== '' ? $businessSlug : null
        );

        if ($user && $throttle->isUserLocked($user)) {
            $throttle->recordFailure($email, $ip, request()->userAgent());
            $this->hitLoginCounters($email, $ip);

            // Mensaje genérico: no confirmar que la cuenta existe/está bloqueada
            throw ValidationException::withMessages([
                'data.email' => $this->failedLoginMessage(),
            ]);
        }

        $passwordOk = $user && Hash::check((string) ($data['password'] ?? ''), $user->password);

        if (! $passwordOk) {
            $throttle->recordFailure($email, $ip, request()->userAgent());
            $this->hitLoginCounters($email, $ip);

            throw ValidationException::withMessages([
                'data.email' => $this->failedLoginMessage(),
            ]);
        }

        Filament::auth()->login($user, (bool) ($data['remember'] ?? false));

        $authUser = Filament::auth()->user();

        if (
            $authUser instanceof FilamentUser
            && (! $authUser->canAccessPanel(Filament::getCurrentPanel()))
        ) {
            Filament::auth()->logout();
            $throttle->recordFailure($email, $ip, request()->userAgent());
            $this->hitLoginCounters($email, $ip);

            // FASE P1.2: no revelar el motivo específico en el propio
            // login form (evita leak "la contraseña era correcta").
            // El motivo humano se muestra tras el intento en la redirect
            // page (flash) para el user legítimo que ya está autenticado.
            $reason = $this->accessDenialReason($authUser);
            if ($reason !== null) {
                session()->flash('login.notice', $reason);
            }

            throw ValidationException::withMessages([
                'data.email' => $this->failedLoginMessage(),
            ]);
        }

        session()->regenerate();
        SessionFingerprint::store(request());
        $this->clearLoginCounters($email, $ip);

        // FASE 3: reescribir contexto (negocio, rol, sucursal, panel destino)
        // JUSTO después de regenerate(). Este era el bug: la sesión se
        // regeneraba y nadie volvía a poblar current_business_id.
        if ($authUser instanceof User) {
            $preferred = app(MembershipIdentity::class)->preferredTenant(
                $authUser,
                $businessSlug !== '' ? $businessSlug : null
            );
            $context = app(AuthContextResolver::class)->apply($authUser, $preferred);

            if ($context['status'] !== AuthContextResolver::STATUS_OK) {
                Filament::auth()->logout();
                // No invalidate()/regenerateToken(): eso deja el form con CSRF
                // viejo y el siguiente clic muestra "This page has expired".

                if (! empty($context['message'])) {
                    session()->flash('login.notice', $context['message']);
                }

                throw ValidationException::withMessages([
                    'data.email' => $this->failedLoginMessage(),
                ]);
            }

            $throttle->recordSuccess($authUser, $ip, request()->userAgent());
        }

        return app(LoginResponse::class);
    }

    /**
     * Motivo específico por el que un usuario autenticado NO puede entrar
     * al panel actual. Devuelve null si no aplica (usar mensaje genérico).
     */
    protected function accessDenialReason(FilamentUser $user): ?string
    {
        if (! $user instanceof User) {
            return null;
        }

        if (($user->is_active ?? true) === false) {
            return 'Tu cuenta está desactivada. Contacta al dueño del negocio.';
        }

        if (! $user->activeBusinesses()->exists()) {
            return 'Tu cuenta no tiene ningún negocio asignado. Contacta al dueño.';
        }

        return null;
    }

    protected function failedLoginMessage(): string
    {
        return (string) config(
            'security.login_failed_message',
            'Estas credenciales no coinciden con nuestros registros.'
        );
    }

    protected function ensureLoginRateLimits(string $email, string $ip): void
    {
        $emailIpKey = 'login|hit|email-ip|'.sha1($email.'|'.$ip);
        $ipKey = 'login|hit|ip|'.$ip;

        $maxEmailIp = max(1, (int) config('security.login_rate_limit.per_minute_email_ip', 5));
        $maxIp = max(1, (int) config('security.login_rate_limit.per_minute_ip', 15));

        if (RateLimiter::tooManyAttempts($emailIpKey, $maxEmailIp)
            || RateLimiter::tooManyAttempts($ipKey, $maxIp)) {
            $seconds = max(
                RateLimiter::availableIn($emailIpKey),
                RateLimiter::availableIn($ipKey),
                1
            );

            throw ValidationException::withMessages([
                'data.email' => __('Demasiados intentos. Espera :seconds segundos e inténtalo de nuevo.', [
                    'seconds' => $seconds,
                ]),
            ]);
        }
    }

    protected function hitLoginCounters(string $email, string $ip): void
    {
        RateLimiter::hit('login|hit|email-ip|'.sha1($email.'|'.$ip), 60);
        RateLimiter::hit('login|hit|ip|'.$ip, 60);
    }

    protected function clearLoginCounters(string $email, string $ip): void
    {
        RateLimiter::clear('login|hit|email-ip|'.sha1($email.'|'.$ip));
        RateLimiter::clear('login|hit|ip|'.$ip);
    }

    /**
     * @return array<string, \Filament\Forms\Form>
     */
    protected function getForms(): array
    {
        return [
            'form' => $this->form(
                $this->makeForm()
                    ->schema([
                        $this->getBusinessSlugFormComponent(),
                        $this->getEmailFormComponent(),
                        $this->getPasswordFormComponent(),
                        $this->getRememberFormComponent(),
                    ])
                    ->statePath('data'),
            ),
        ];
    }

    protected function getBusinessSlugFormComponent(): Component
    {
        $required = (bool) config('scale.auth.require_business_slug');

        return TextInput::make('business_slug')
            ->label('Negocio')
            ->placeholder('slug-del-comercio')
            ->helperText($required
                ? 'Identificador del comercio (el de la URL /admin/{slug}).'
                : 'Opcional. Úsalo si trabajas en más de un comercio.')
            ->required($required)
            ->autocomplete('organization')
            ->extraInputAttributes(['tabindex' => 1]);
    }

    protected function getEmailFormComponent(): Component
    {
        return TextInput::make('email')
            ->label('Correo o usuario de caja')
            ->required()
            ->autocomplete('username')
            ->autofocus(fn (): bool => ! config('scale.auth.require_business_slug'))
            ->extraInputAttributes(['tabindex' => 2]);
    }

    protected function getPasswordFormComponent(): Component
    {
        return TextInput::make('password')
            ->label(__('filament-panels::pages/auth/login.form.password.label'))
            ->password()
            ->revealable(filament()->arePasswordsRevealable())
            ->autocomplete('current-password')
            ->required()
            ->extraInputAttributes(['tabindex' => 3]);
    }
}
