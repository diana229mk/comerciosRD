<?php

use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schedule;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

// P2.3: limpiar filas caducadas de la tabla `sessions` una vez al día.
// Solo aplica si SESSION_DRIVER=database. Complementa el GC nativo de PHP
// para SESSION_DRIVER=file.
Schedule::command('session:prune')
    ->daily()
    ->when(fn () => config('session.driver') === 'database')
    ->onOneServer()
    ->withoutOverlapping();

// P2.3: limpiar tabla `login_attempts` de intentos viejos (>7 días) para
// mantener la escalera de bloqueo relevante y evitar crecimiento sin fin.
Schedule::call(function (): void {
    DB::table('login_attempts')
        ->where('attempted_at', '<', now()->subDays(7))
        ->delete();
})->daily()->name('prune-login-attempts')->onOneServer();

// Resumen diario: corrige drift de los últimos 7 días (default del comando).
Schedule::command('subscriptions:generate-invoices')
    ->dailyAt('06:10')
    ->onOneServer()
    ->withoutOverlapping();

Schedule::command('sales:rebuild-daily')
    ->dailyAt('01:20')
    ->onOneServer()
    ->withoutOverlapping();

// Los leases SQL permiten concurrencia sin depender de locks en Redis.
Schedule::command('accounting:outbox-dispatch')
    ->everyMinute()
    ->when(fn () => (bool) config('scale.post_sale_queue'));

// Compatibilidad de efectos heredados; no es la recuperación durable del outbox.
Schedule::command('sales:reconcile-side-effects --hours=24')
    ->hourly()
    ->onOneServer()
    ->withoutOverlapping();

// Copia diaria para poder cambiar de proveedor sin tocar la base en uso.
Schedule::command('portability:backup')
    ->dailyAt('02:15')
    ->when(fn () => (bool) config('portability.backup_enabled'))
    ->onOneServer()
    ->withoutOverlapping();
