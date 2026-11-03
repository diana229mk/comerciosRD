<?php

namespace App\Console\Commands;

use App\Services\MysqlTlsPolicy;
use Illuminate\Console\Command;

/**
 * Preflight de seguridad para producción.
 *
 * Uso en el pipeline de deploy ANTES de encender la app:
 *   php artisan security:preflight --strict
 *
 * Sin --strict: reporta warnings pero termina con exit 0.
 * Con --strict: termina con exit 1 si hay alguna violación.
 */
class SecurityPreflight extends Command
{
    protected $signature = 'security:preflight {--strict : Termina con exit 1 si hay violaciones}';

    protected $description = 'Valida configuración crítica de seguridad antes de arrancar en producción.';

    public function handle(): int
    {
        $env = (string) config('app.env');
        $isProd = $env === 'production';

        $this->info("Entorno: {$env}");
        $this->newLine();

        $violations = [];
        $warnings = [];

        if (config('database.default') === 'mysql') {
            try {
                MysqlTlsPolicy::pdoOptions((array) config('database.connections.mysql'));
            } catch (\RuntimeException $exception) {
                $violations[] = $exception->getMessage();
            }
        }

        if (config('ecf.enabled') && ! config('ecf.validation_approved')) {
            $violations[] = 'ECF_ENABLED=true no habilita e-CF: falta aprobación de validación XAdES/DGII en el código.';
        }

        if (blank(config('app.key'))) {
            $violations[] = 'APP_KEY está vacía. Genera una con `php artisan key:generate` y ¡NO la rotes después!';
        }

        if ($isProd && ! config('security.two_factor.enabled')) {
            $violations[] = 'SECURITY_2FA_ENABLED=false. La autenticación de dos factores debe estar activa en producción.';
        }

        if ($isProd) {
            $requiredRoles = (array) config('security.two_factor.required_roles', []);
            foreach (['owner', 'admin'] as $role) {
                if (! in_array($role, $requiredRoles, true)) {
                    $violations[] = "El rol {$role} no tiene 2FA obligatorio en producción.";
                }
            }

            if (! config('security.two_factor.require_master', false)) {
                $violations[] = 'SECURITY_2FA_REQUIRE_MASTER debe estar activo en producción.';
            }
        }

        if ($isProd && config('app.debug')) {
            $violations[] = 'APP_DEBUG=true en producción → filtra stack traces con SQL y rutas. Debe ser false.';
        }

        $driver = (string) config('session.driver');
        if ($isProd && $driver === 'file') {
            $violations[] = 'SESSION_DRIVER=file no sobrevive deploys en la mayoría de hosts. Usa `database` o `redis`.';
        } elseif (! in_array($driver, ['database', 'redis', 'memcached'], true)) {
            $warnings[] = "SESSION_DRIVER={$driver} es inusual en producción. Recomendado: database o redis.";
        }

        $appUrl = (string) config('app.url');
        if ($isProd && str_starts_with($appUrl, 'https://') && ! config('session.secure')) {
            $violations[] = 'SESSION_SECURE_COOKIE debe ser true cuando APP_URL usa https://.';
        }

        if ($isProd && ! config('session.encrypt')) {
            $warnings[] = 'SESSION_ENCRYPT=false. Recomendado true en producción.';
        }

        if ($isProd && ! config('security.session_fingerprint.enabled', true)) {
            $warnings[] = 'SECURITY_SESSION_FINGERPRINT=false. Recomendado true para detectar secuestro de sesión.';
        }

        if ($isProd && ! str_starts_with($appUrl, 'https://')) {
            $warnings[] = "APP_URL={$appUrl} no es HTTPS. Producción sin HTTPS es un no-go.";
        }

        $cacheStore = (string) config('cache.default');
        if ($isProd && in_array($cacheStore, ['database', 'file'], true)) {
            $warnings[] = "CACHE_STORE={$cacheStore} no aguanta cientos de cajas. Usa redis.";
        }

        $queue = (string) config('queue.default');
        if ($isProd && $queue === 'sync') {
            $violations[] = 'QUEUE_CONNECTION=sync en producción ejecuta e-CF/PDF/asientos en el request del cajero.';
        } elseif ($isProd && $queue === 'database') {
            $warnings[] = 'QUEUE_CONNECTION=database funciona, pero redis + worker `--queue=sales,reports,default` escala mejor.';
        }

        if ($isProd && ! config('scale.post_sale_queue') && $queue !== 'sync') {
            $warnings[] = 'SCALE_POST_SALE_QUEUE=false: el cobro sigue haciendo asiento y e-CF en el mismo request. Actívalo cuando el worker esté supervisado.';
        }

        $sessionDriver = (string) config('session.driver');
        if ($isProd && $sessionDriver === 'database' && $cacheStore === 'database') {
            $warnings[] = 'Sesión y cache en MySQL no sostienen 100 cajas concurrentes. Usa redis en ambos.';
        }

        if ($isProd && config('scale.target_concurrent_users', 10000) >= 10000) {
            if ($sessionDriver !== 'redis') {
                $violations[] = 'La meta configurada es 10,000 usuarios concurrentes: SESSION_DRIVER debe ser redis y compartido por todos los servidores.';
            }
            if (! config('session.encrypt')) {
                $violations[] = 'La meta configurada es 10,000 usuarios concurrentes: SESSION_ENCRYPT debe ser true.';
            }
            if (! str_starts_with($appUrl, 'https://') || ! config('session.secure')) {
                $violations[] = 'La meta configurada es 10,000 usuarios concurrentes: APP_URL debe usar HTTPS y SESSION_SECURE_COOKIE=true.';
            }
            if ($cacheStore !== 'redis') {
                $violations[] = 'La meta configurada es 10,000 usuarios concurrentes: CACHE_STORE debe ser redis y compartido por todos los servidores.';
            }
            if ($queue !== 'redis') {
                $violations[] = 'La meta configurada es 10,000 usuarios concurrentes: QUEUE_CONNECTION debe ser redis.';
            }
            if (! config('scale.post_sale_queue')) {
                $violations[] = 'La meta configurada es 10,000 usuarios concurrentes: SCALE_POST_SALE_QUEUE debe estar activo y sus workers supervisados.';
            }
        }

        if ($isProd && ! config('portability.backup_enabled')) {
            $violations[] = 'PORTABILITY_BACKUP_ENABLED=false. Debe existir una ruta de backup operativa antes de producción.';
        }

        if ($isProd && (
            config('filesystems.default') === 's3'
            || config('filesystems.disks.private.driver', 'local') === 's3'
        )) {
            $violations[] = 'El backup portability local no incluye buckets S3/Spaces; verifica una estrategia externa de respaldo y restauración de objetos antes del deploy.';
        }

        foreach ($warnings as $w) {
            $this->warn('⚠  '.$w);
        }
        foreach ($violations as $v) {
            $this->error('✖  '.$v);
        }

        if (empty($violations) && empty($warnings)) {
            $this->info('✓ Todos los chequeos de seguridad pasaron.');

            return self::SUCCESS;
        }

        $this->newLine();
        $this->line('Violaciones: '.count($violations).' | Warnings: '.count($warnings));

        if (! empty($violations) && $this->option('strict')) {
            return self::FAILURE;
        }

        return self::SUCCESS;
    }
}
