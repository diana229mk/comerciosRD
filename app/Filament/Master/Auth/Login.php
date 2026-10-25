<?php

namespace App\Filament\Master\Auth;

use App\Http\Middleware\SessionFingerprint;
use App\Models\MasterAuditLog;
use App\Models\User;
use App\Services\AuditLogger;
use App\Services\LoginThrottleService;
use App\Services\MasterLoginAlertService;
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
 * Login del Panel Master con rate limiting estricto.
 *
 * Capas:
 *  1) RateLimiter por IP (5/min, 15/h, 30/día)
 *  2) RateLimiter por email (5/min, 15/h) — resiste VPN/proxy
 *  3) Escalera LoginThrottleService (bloqueo progresivo)
 *  4) Livewire rateLimit (ráfagas)
 *  5) Alerta email a Master Admins al superar límites
 */
class Login extends BaseLogin
{
    public function authenticate(): ?LoginResponse
    {
        $data = $this->form->getState();
        $email = strtolower(trim((string) ($data['email'] ?? '')));
        $ip = (string) request()->ip();
        $throttle = app(LoginThrottleService::class);

        // 1 + 2) Límites por IP y por email
        $this->ensureMasterRateLimits($ip, $email);

        // 3) Escalera de bloqueo master (email+IP en login_attempts)
        if ($throttle->tooManyAttempts($email, $ip, $this->masterLadder())) {
            $seconds = $throttle->availableInSeconds($email, $ip, $this->masterLadder());

            $this->auditBlocked($email, $ip, 'ladder');
            $this->alertMasters($ip, $email, 'escalera de bloqueo');

            throw ValidationException::withMessages([
                'data.email' => __('Demasiados intentos fallidos. Espera :seconds segundos antes de volver a intentar.', [
                    'seconds' => max(1, $seconds),
                ]),
            ]);
        }

        // 4) Livewire rate limit (ráfagas en el componente)
        $perMinute = max(1, (int) config('security.master_login_throttle.per_minute', 5));

        try {
            $this->rateLimit($perMinute);
        } catch (TooManyRequestsException $exception) {
            $throttle->recordFailure($email, $ip, request()->userAgent());
            $this->hitMasterCounters($ip, $email);
            $this->auditBlocked($email, $ip, 'livewire');
            $this->alertMasters($ip, $email, '1 minuto');

            throw ValidationException::withMessages([
                'data.email' => __('Demasiados intentos fallidos. Por favor espera :seconds segundos antes de volver a intentar.', [
                    'seconds' => $exception->secondsUntilAvailable,
                ]),
            ]);
        }

        $user = User::query()->where('email', $email)->first();

        if ($user && $throttle->isUserLocked($user)) {
            $throttle->recordFailure($email, $ip, request()->userAgent());
            $this->hitMasterCounters($ip, $email);

            // Mensaje genérico (no confirmar bloqueo de cuenta)
            throw ValidationException::withMessages([
                'data.email' => $this->failedLoginMessage(),
            ]);
        }

        if (! Filament::auth()->attempt($this->getCredentialsFromFormData($data), $data['remember'] ?? false)) {
            $throttle->recordFailure($email, $ip, request()->userAgent());
            $this->hitMasterCounters($ip, $email);
            $this->maybeAlertAfterHit($ip, $email);

            throw ValidationException::withMessages([
                'data.email' => $this->failedLoginMessage(),
            ]);
        }

        $authUser = Filament::auth()->user();

        // Solo master admins en este panel
        if (! $authUser instanceof User || ! ($authUser->is_master_admin ?? false)) {
            Filament::auth()->logout();
            $throttle->recordFailure($email, $ip, request()->userAgent());
            $this->hitMasterCounters($ip, $email);
            $this->maybeAlertAfterHit($ip, $email);

            // Mensaje genérico: no revelar rutas de otros paneles ni roles
            throw ValidationException::withMessages([
                'data.email' => $this->failedLoginMessage(),
            ]);
        }

        if (
            $authUser instanceof FilamentUser
            && (! $authUser->canAccessPanel(Filament::getCurrentPanel()))
        ) {
            Filament::auth()->logout();
            $throttle->recordFailure($email, $ip, request()->userAgent());
            $this->hitMasterCounters($ip, $email);

            // P1.2: no revelar motivo en el form. Se guarda como flash
            // para eventual UX post-redirect.
            $reason = ($authUser instanceof \App\Models\User && ($authUser->is_active ?? true) === false)
                ? 'Tu cuenta está desactivada.'
                : 'No tienes acceso al panel master.';
            session()->flash('login.notice', $reason);

            throw ValidationException::withMessages([
                'data.email' => $this->failedLoginMessage(),
            ]);
        }

        session()->regenerate();
        SessionFingerprint::store(request());

        // FASE 3: contexto para master (sin tenant).
        if ($authUser instanceof \App\Models\User) {
            app(\App\Services\AuthContextResolver::class)->apply($authUser);
        }

        $throttle->recordSuccess($authUser, $ip, request()->userAgent());
        $this->clearMasterCounters($ip, $email);

        MasterAuditLog::log(
            'master_login_success',
            $authUser,
            [],
            ['ip' => $ip, 'email' => $authUser->email]
        );

        return app(LoginResponse::class);
    }

    /**
     * @return array<int, int>
     */
    protected function masterLadder(): array
    {
        return config('security.master_login_throttle.ladder', [
            2 => 2,
            4 => 15,
            6 => 60,
            10 => 1440,
        ]);
    }

    /**
     * Verifica límites por IP y por email antes de intentar autenticar.
     */
    protected function ensureMasterRateLimits(string $ip, string $email): void
    {
        $cfg = config('security.master_login_throttle', []);

        $checks = [
            [
                'key' => $this->ipKey($ip, 'min'),
                'max' => max(1, (int) ($cfg['per_minute'] ?? 5)),
                'label' => 'IP · 1 minuto',
                'scope' => 'ip',
            ],
            [
                'key' => $this->ipKey($ip, 'hour'),
                'max' => max(1, (int) ($cfg['per_hour'] ?? 15)),
                'label' => 'IP · 1 hora',
                'scope' => 'ip',
            ],
            [
                'key' => $this->ipKey($ip, 'day'),
                'max' => max(1, (int) ($cfg['per_day'] ?? 30)),
                'label' => 'IP · 24 horas',
                'scope' => 'ip',
            ],
        ];

        if ($email !== '') {
            $checks[] = [
                'key' => $this->emailKey($email, 'min'),
                'max' => max(1, (int) ($cfg['per_email_minute'] ?? 5)),
                'label' => 'Email · 1 minuto',
                'scope' => 'email',
            ];
            $checks[] = [
                'key' => $this->emailKey($email, 'hour'),
                'max' => max(1, (int) ($cfg['per_email_hour'] ?? 15)),
                'label' => 'Email · 1 hora',
                'scope' => 'email',
            ];
        }

        foreach ($checks as $check) {
            if (RateLimiter::tooManyAttempts($check['key'], $check['max'])) {
                $seconds = RateLimiter::availableIn($check['key']);

                app(AuditLogger::class)->warning(
                    'master.login.rate_limited',
                    "Login Master bloqueado por rate limit ({$check['label']})",
                    [
                        'ip' => $ip,
                        'email' => $email,
                        'window' => $check['label'],
                        'scope' => $check['scope'],
                        'seconds' => $seconds,
                    ]
                );

                $this->alertMasters($ip, $email, $check['label'], $check['max']);

                throw ValidationException::withMessages([
                    'data.email' => __('Demasiados intentos fallidos. Por seguridad bloqueamos temporalmente el acceso por IP y correo. Espera :seconds segundos (límite: :window). Se ha alertado a los administradores.', [
                        'seconds' => max(1, $seconds),
                        'window' => $check['label'],
                    ]),
                ]);
            }
        }
    }

    protected function hitMasterCounters(string $ip, string $email): void
    {
        // IP
        RateLimiter::hit($this->ipKey($ip, 'min'), 60);
        RateLimiter::hit($this->ipKey($ip, 'hour'), 3600);
        RateLimiter::hit($this->ipKey($ip, 'day'), 86400);

        // Email (aunque cambien de IP)
        if ($email !== '') {
            RateLimiter::hit($this->emailKey($email, 'min'), 60);
            RateLimiter::hit($this->emailKey($email, 'hour'), 3600);
        }
    }

    /**
     * Tras un fallo: si se cruzó umbral IP o email, alertar.
     */
    protected function maybeAlertAfterHit(string $ip, string $email): void
    {
        $cfg = config('security.master_login_throttle', []);
        $ipMax = max(1, (int) ($cfg['per_minute'] ?? 5));
        $emailMax = max(1, (int) ($cfg['per_email_minute'] ?? 5));

        if (RateLimiter::tooManyAttempts($this->ipKey($ip, 'min'), $ipMax)) {
            $this->alertMasters($ip, $email, 'IP · 1 minuto', $ipMax);

            return;
        }

        if ($email !== '' && RateLimiter::tooManyAttempts($this->emailKey($email, 'min'), $emailMax)) {
            $this->alertMasters($ip, $email, 'Email · 1 minuto', $emailMax);
        }
    }

    protected function alertMasters(
        string $ip,
        ?string $email,
        string $window,
        ?int $attempts = null,
    ): void {
        $attempts ??= max(1, (int) config('security.master_login_throttle.per_minute', 5));

        app(MasterLoginAlertService::class)->notifyRateLimitExceeded(
            ip: $ip,
            attempts: $attempts,
            window: $window,
            emailAttempted: $email,
        );
    }

    protected function clearMasterCounters(string $ip, string $email): void
    {
        // Solo limpiamos la ventana corta de IP/email en login exitoso
        RateLimiter::clear($this->ipKey($ip, 'min'));

        if ($email !== '') {
            RateLimiter::clear($this->emailKey($email, 'min'));
        }
        // hour/day se mantienen para medir abuso sostenido
    }

    protected function ipKey(string $ip, string $window): string
    {
        return 'master-login|ip|'.$window.'|'.$ip;
    }

    protected function emailKey(string $email, string $window): string
    {
        return 'master-login|email|'.$window.'|'.sha1(strtolower(trim($email)));
    }

    protected function auditBlocked(string $email, string $ip, string $reason): void
    {
        app(AuditLogger::class)->warning(
            'master.login.blocked',
            "Login Master bloqueado ({$reason}) para {$email}",
            [
                'email' => $email,
                'ip' => $ip,
                'reason' => $reason,
            ]
        );
    }

    protected function failedLoginMessage(): string
    {
        return (string) config(
            'security.login_failed_message',
            'Estas credenciales no coinciden con nuestros registros.'
        );
    }

    protected function getPasswordFormComponent(): Component
    {
        return TextInput::make('password')
            ->label(__('filament-panels::pages/auth/login.form.password.label'))
            ->password()
            ->revealable(filament()->arePasswordsRevealable())
            ->autocomplete('current-password')
            ->required()
            ->extraInputAttributes(['tabindex' => 2]);
    }

    /**
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    protected function getCredentialsFromFormData(array $data): array
    {
        return [
            'email' => strtolower(trim((string) ($data['email'] ?? ''))),
            'password' => $data['password'] ?? '',
        ];
    }
}
