<?php

namespace App\Services;

use App\Models\AuditLog;
use App\Models\LoginAttempt;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;

/**
 * Alertas automáticas ante eventos de seguridad.
 */
class SecurityAlertService
{
    public function __construct(
        protected AuditLogger $audit
    ) {}

    public function bruteForceDetected(string $ip, ?string $email = null, int $attempts = 0): void
    {
        $threshold = (int) config('security.alerts.brute_force_threshold', 8);

        if ($attempts < $threshold) {
            return;
        }

        $this->audit->critical(
            'security.brute_force',
            "Posible fuerza bruta desde IP {$ip}".($email ? " (email: {$email})" : ''),
            [
                'ip' => $ip,
                'email' => $email,
                'attempts' => $attempts,
            ]
        );

        $this->notify('Brute force detectado', [
            'ip' => $ip,
            'email' => $email,
            'attempts' => $attempts,
        ]);
    }

    public function crossTenantAccessAttempt(string $userId, string $targetBusinessId, ?string $recordType = null): void
    {
        $this->audit->critical(
            'security.cross_tenant',
            'Intento de acceso a recurso de otro tenant (404 servido)',
            [
                'user_id' => $userId,
                'target_business_id' => $targetBusinessId,
                'record_type' => $recordType,
            ]
        );

        $window = now()->subHour();
        $count = AuditLog::query()
            ->where('user_id', $userId)
            ->where('event', 'security.cross_tenant')
            ->where('created_at', '>=', $window)
            ->count();

        $threshold = (int) config('security.alerts.cross_tenant_threshold', 3);

        if ($count >= $threshold) {
            $this->notify('Múltiples intentos cross-tenant', [
                'user_id' => $userId,
                'count_1h' => $count,
                'target_business_id' => $targetBusinessId,
            ]);
        }
    }

    public function masterPanelUnauthorizedIp(string $ip, ?string $userId = null): void
    {
        $this->audit->critical(
            'security.master_ip_denied',
            "Acceso al Panel Master bloqueado desde IP no autorizada: {$ip}",
            ['ip' => $ip, 'user_id' => $userId]
        );

        $this->notify('Master panel — IP no autorizada', [
            'ip' => $ip,
            'user_id' => $userId,
        ]);
    }

    public function sessionHijackSuspected(string $userId, array $details = []): void
    {
        $this->audit->critical(
            'security.session_hijack',
            'Fingerprint de sesión no coincide — sesión invalidada',
            array_merge(['user_id' => $userId], $details)
        );

        $this->notify('Posible secuestro de sesión', array_merge(
            ['user_id' => $userId],
            $details
        ));
    }

    public function recentFailedLogins(string $ip, int $minutes = 15): int
    {
        return LoginAttempt::query()
            ->where('ip_address', $ip)
            ->where('successful', false)
            ->where('attempted_at', '>=', now()->subMinutes($minutes))
            ->count();
    }

    /**
     * @param  array<string, mixed>  $context
     */
    protected function notify(string $subject, array $context): void
    {
        $channel = config('security.alerts.log_channel', 'stack');
        Log::channel($channel)->warning('[SECURITY] '.$subject, $context);

        $mail = config('security.alerts.notify_mail');
        if (blank($mail)) {
            return;
        }

        try {
            Mail::raw(
                $subject."\n\n".json_encode($context, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE),
                function ($message) use ($mail, $subject) {
                    $message->to($mail)->subject('[ComerciosRD Security] '.$subject);
                }
            );
        } catch (\Throwable $e) {
            report($e);
        }
    }
}
