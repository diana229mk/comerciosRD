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

    // Solo después de probar backup y restauración del bucket. No copia objetos por sí solo.
    'object_backup_verified' => (bool) env('PORTABILITY_OBJECT_BACKUP_VERIFIED', false),

    // Cuenta de solo lectura para mysqldump (SELECT, SHOW VIEW, TRIGGER, EVENT, LOCK TABLES,
    // PROCESS). Vacía = usa DB_USERNAME; con privilegios mínimos la cuenta de la app no basta.
    'db_username' => env('PORTABILITY_DB_USERNAME'),
    'db_password' => env('PORTABILITY_DB_PASSWORD'),

];
