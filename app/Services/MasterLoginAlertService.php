<?php

namespace App\Services;

use App\Models\User;
use App\Notifications\MasterFailedLoginAttempt;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Notification;

/**
 * Notifica a todos los Master Admins (con anti-spam por IP).
 */
class MasterLoginAlertService
{
    /**
     * Envía email a masters una sola vez por IP cada N minutos.
     */
    public function notifyRateLimitExceeded(
        string $ip,
        int $attempts,
        string $window = '1 minuto',
        ?string $emailAttempted = null,
    ): void {
        $cooldownMinutes = (int) config('security.master_login_throttle.alert_cooldown_minutes', 15);
        $cacheKey = 'master-login-alert:'.sha1($ip.'|'.$window);

        // Evitar spamear el mismo email cada request bloqueado
        if (! Cache::add($cacheKey, true, now()->addMinutes(max(1, $cooldownMinutes)))) {
            return;
        }

        $admins = User::query()
            ->where('is_master_admin', true)
            ->where('is_active', true)
            ->whereNotNull('email')
            ->get();

        if ($admins->isEmpty()) {
            return;
        }

        $notification = new MasterFailedLoginAttempt(
            ipAddress: $ip,
            attempts: $attempts,
            window: $window,
            emailAttempted: $emailAttempted,
        );

        try {
            // sendNow: la alerta de seguridad no debe depender de un worker caído
            Notification::sendNow($admins, $notification);
        } catch (\Throwable $e) {
            report($e);

            // Fallback: encolar si sendNow falla por driver
            try {
                Notification::send($admins, $notification);
            } catch (\Throwable $e2) {
                report($e2);
            }
        }

        app(AuditLogger::class)->critical(
            'master.login.alert_sent',
            "Alerta de fuerza bruta Master enviada a {$admins->count()} admin(s)",
            [
                'ip' => $ip,
                'attempts' => $attempts,
                'window' => $window,
                'email_attempted' => $emailAttempted,
                'notified' => $admins->pluck('email')->all(),
            ]
        );
    }
}
