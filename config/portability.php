<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Backup automático (scheduler)
    |--------------------------------------------------------------------------
    |
    | En producción pon PORTABILITY_BACKUP_ENABLED=true y un cron de
    | `php artisan schedule:run` cada minuto. El dump NUNCA se restaura
    | sobre DB_DATABASE: el comando restore exige otra base.
    |
    */
    'backup_enabled' => (bool) env('PORTABILITY_BACKUP_ENABLED', false),

    'backup_dir' => env('PORTABILITY_BACKUP_DIR', storage_path('app/private/backups')),

    'keep_days' => (int) env('PORTABILITY_BACKUP_KEEP_DAYS', 14),

];
