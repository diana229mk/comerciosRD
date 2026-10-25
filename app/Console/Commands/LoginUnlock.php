<?php

namespace App\Console\Commands;

use App\Models\User;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\RateLimiter;

/**
 * Desbloquea el rate limiter y la escalera de bloqueo de login.
 *
 * Casos de uso:
 *  - El dueño quedó bloqueado tras intentos fallidos.
 *  - En producción, un cliente reporta "Demasiados intentos".
 *
 * Uso:
 *   php artisan login:unlock                        # limpia counters globales (peligro: solo dev)
 *   php artisan login:unlock --email=x@y.com        # limpia counters + escalera para ese email
 *   php artisan login:unlock --email=x@y.com --ip=1.2.3.4
 */
class LoginUnlock extends Command
{
    protected $signature = 'login:unlock
        {--email= : Email del usuario a desbloquear}
        {--ip= : IP a desbloquear (opcional, complementa a --email)}';

    protected $description = 'Limpia contadores de rate limiting y bloqueos de login para un usuario o IP.';

    public function handle(): int
    {
        $email = strtolower(trim((string) $this->option('email')));
        $ip = (string) $this->option('ip');

        if ($email === '' && $ip === '') {
            if (! $this->confirm('¿Limpiar TODOS los contadores de login? (solo dev)', false)) {
                $this->warn('Cancelado.');

                return self::SUCCESS;
            }
            Cache::flush();
            DB::table('login_attempts')->truncate();
            User::query()->update([
                'failed_login_attempts' => 0,
                'locked_until' => null,
            ]);
            $this->info('✓ Todos los contadores limpiados.');

            return self::SUCCESS;
        }

        if ($email !== '') {
            $keys = [
                'login|hit|email-ip|'.sha1($email.'|'.$ip),
                'login|email-ip|'.sha1($email.'|'.$ip),
                'master-login|email|min|'.sha1($email),
                'master-login|email|hour|'.sha1($email),
            ];
            foreach ($keys as $k) {
                RateLimiter::clear($k);
            }

            DB::table('login_attempts')->where('email', $email)->delete();

            User::query()
                ->where('email', $email)
                ->update([
                    'failed_login_attempts' => 0,
                    'locked_until' => null,
                ]);

            $this->info("✓ Desbloqueado email {$email}.");
        }

        if ($ip !== '') {
            $ipKeys = [
                'login|hit|ip|'.$ip,
                'login|ip|'.$ip,
                'master-login|ip|min|'.$ip,
                'master-login|ip|hour|'.$ip,
                'master-login|ip|day|'.$ip,
            ];
            foreach ($ipKeys as $k) {
                RateLimiter::clear($k);
            }
            DB::table('login_attempts')->where('ip', $ip)->delete();
            $this->info("✓ Desbloqueado IP {$ip}.");
        }

        return self::SUCCESS;
    }
}
