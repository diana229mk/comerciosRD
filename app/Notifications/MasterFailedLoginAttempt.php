<?php

namespace App\Notifications;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

/**
 * Alerta por email a Master Admins ante fuerza bruta en /master/login.
 *
 * Nota: usa cola (ShouldQueue). Con QUEUE_CONNECTION=database hace falta
 * `php artisan queue:work`. Si prefieres envío inmediato, quita ShouldQueue.
 */
class MasterFailedLoginAttempt extends Notification implements ShouldQueue
{
    use Queueable;

    public function __construct(
        public string $ipAddress,
        public int $attempts = 5,
        public string $window = '1 minuto',
        public ?string $emailAttempted = null,
    ) {}

    public function via(object $notifiable): array
    {
        return ['mail'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        $mail = (new MailMessage)
            ->subject('⚠️ Alerta de Seguridad - Panel Master')
            ->greeting('Hola '.($notifiable->name ?? '').',')
            ->line('Se han detectado múltiples intentos fallidos de inicio de sesión en el **Panel Master**.')
            ->line("**Dirección IP:** {$this->ipAddress}")
            ->line("**Intentos fallidos:** {$this->attempts} (ventana: {$this->window}).");

        if ($this->emailAttempted) {
            $mail->line("**Email intentado:** {$this->emailAttempted}");
        }

        return $mail
            ->line('Esto puede indicar un intento de acceso no autorizado.')
            ->action('Ir al Panel Master', url('/master'))
            ->line('Si no reconoces esta actividad, revisa Audit Log / logs y considera bloquear la IP.')
            ->salutation('Saludos, Equipo de Seguridad ComerciosRD');
    }
}
