<?php

namespace App\Services;

use App\Models\LoginAttempt;
use App\Models\User;
use Illuminate\Support\Facades\RateLimiter;

/**
 * Rate limiting escalonado de login + registro de intentos.
 */
class LoginThrottleService
{
    public function __construct(
        protected AuditLogger $audit,
        protected SecurityAlertService $alerts,
    ) {}

    public function key(string $email, string $ip): string
    {
        return 'login:'.sha1(strtolower($email).'|'.$ip);
    }

    /**
     * Minutos de bloqueo según fallos acumulados.
     *
     * @param  array<int, int>|null  $ladder  intentos => minutos
     */
    public function lockoutMinutes(int $failures, ?array $ladder = null): int
    {
        $ladder ??= config('security.login_throttle', [
            3 => 1,
            5 => 15,
            8 => 60,
            12 => 1440,
        ]);

        ksort($ladder);
        $minutes = 0;

        foreach ($ladder as $threshold => $lockMinutes) {
            if ($failures >= (int) $threshold) {
                $minutes = (int) $lockMinutes;
            }
        }

        return $minutes;
    }

    /**
     * @param  array<int, int>|null  $ladder
     */
    public function tooManyAttempts(string $email, string $ip, ?array $ladder = null): bool
    {
        $failures = $this->recentFailures($email, $ip);
        $minutes = $this->lockoutMinutes($failures, $ladder);

        if ($minutes <= 0) {
            return false;
        }

        // Si el último fallo fue hace menos de $minutes, bloquear
        $last = LoginAttempt::query()
            ->where('successful', false)
            ->where(function ($q) use ($email, $ip) {
                $q->where('email', $email)->orWhere('ip_address', $ip);
            })
            ->orderByDesc('attempted_at')
            ->first();

        if (! $last) {
            return false;
        }

        return $last->attempted_at->gt(now()->subMinutes($minutes));
    }

    /**
     * @param  array<int, int>|null  $ladder
     */
    public function availableInSeconds(string $email, string $ip, ?array $ladder = null): int
    {
        $failures = $this->recentFailures($email, $ip);
        $minutes = $this->lockoutMinutes($failures, $ladder);

        if ($minutes <= 0) {
            return 0;
        }

        $last = LoginAttempt::query()
            ->where('successful', false)
            ->where(function ($q) use ($email, $ip) {
                $q->where('email', $email)->orWhere('ip_address', $ip);
            })
            ->orderByDesc('attempted_at')
            ->value('attempted_at');

        if (! $last) {
            return 0;
        }

        $unlockAt = \Carbon\Carbon::parse($last)->addMinutes($minutes);

        return max(0, now()->diffInSeconds($unlockAt, false) > 0
            ? (int) now()->diffInSeconds($unlockAt)
            : 0);
    }

    public function recentFailures(string $email, string $ip, int $hours = 24): int
    {
        return LoginAttempt::query()
            ->where('successful', false)
            ->where('attempted_at', '>=', now()->subHours($hours))
            ->where(function ($q) use ($email, $ip) {
                $q->where('email', $email)->orWhere('ip_address', $ip);
            })
            ->count();
    }

    public function recordFailure(string $email, string $ip, ?string $userAgent = null): void
    {
        LoginAttempt::create([
            'email' => $email,
            'ip_address' => $ip,
            'successful' => false,
            'user_agent' => $userAgent,
            'attempted_at' => now(),
        ]);

        $user = User::query()->where('email', $email)->first();
        if ($user) {
            $user->forceFill([
                'failed_login_attempts' => (int) $user->failed_login_attempts + 1,
                'locked_until' => $this->computeLockedUntil((int) $user->failed_login_attempts + 1),
            ])->saveQuietly();
        }

        $failures = $this->recentFailures($email, $ip);

        $this->audit->warning(
            'login.failed',
            "Intento de login fallido para {$email}",
            [
                'email' => $email,
                'ip' => $ip,
                'failures_24h' => $failures,
            ],
            userId: $user?->getKey()
        );

        $this->alerts->bruteForceDetected($ip, $email, $failures);
    }

    public function recordSuccess(User $user, string $ip, ?string $userAgent = null): void
    {
        LoginAttempt::create([
            'email' => $user->email,
            'ip_address' => $ip,
            'successful' => true,
            'user_agent' => $userAgent,
            'attempted_at' => now(),
        ]);

        $user->forceFill([
            'failed_login_attempts' => 0,
            'locked_until' => null,
            'last_login_ip' => $ip,
            'last_login_at' => now(),
        ])->saveQuietly();

        $this->audit->info(
            'login.success',
            "Login exitoso: {$user->email}",
            ['ip' => $ip],
            userId: (string) $user->getKey()
        );
    }

    public function isUserLocked(User $user): bool
    {
        return $user->locked_until !== null && $user->locked_until->isFuture();
    }

    protected function computeLockedUntil(int $failures): ?\Carbon\CarbonInterface
    {
        // Usar escalera normal (admin); el Master aplica ladder en Login + IP limits
        $minutes = $this->lockoutMinutes($failures);

        return $minutes > 0 ? now()->addMinutes($minutes) : null;
    }
}
