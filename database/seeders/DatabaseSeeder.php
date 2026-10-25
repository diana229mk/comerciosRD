<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;

class DatabaseSeeder extends Seeder
{
    public function run(): void
    {
        // Seeders solo para entornos no productivos.
        // MasterUsersSeeder crea usuarios con clave conocida (Password123!):
        // NUNCA debe correr en producción. Los master admins de prod se
        // crean con `php artisan make:master-admin` (o manualmente).
        if (app()->environment(['local', 'staging', 'testing'])) {
            $this->call([
                MasterUsersSeeder::class,
                ProductDemoSeeder::class,
                CustomerDemoSeeder::class,
            ]);

            return;
        }

        // En producción no se hace nada por defecto. Para el backfill de
        // owners histórico corre manualmente:
        //   php artisan db:seed --class=BackfillBusinessOwnersSeeder
    }
}
