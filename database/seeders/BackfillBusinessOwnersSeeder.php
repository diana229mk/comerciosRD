<?php

namespace Database\Seeders;

use App\Models\Business;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;

/**
 * Backfill idempotente de dueños en el pivot `user_business`.
 *
 * Garantiza que cada `businesses.owner_id` tiene su fila con:
 *   role = 'owner'
 *   is_active = true
 *
 * SEGURO para producción:
 *  - Nunca borra filas.
 *  - Nunca crea negocios ni usuarios.
 *  - Reporta métricas al final.
 *
 * Ejecutar manualmente:
 *   php artisan db:seed --class=BackfillBusinessOwnersSeeder
 */
class BackfillBusinessOwnersSeeder extends Seeder
{
    public function run(): void
    {
        $stats = [
            'businesses_scanned' => 0,
            'owners_ok' => 0,
            'pivots_created' => 0,
            'pivots_reactivated' => 0,
            'roles_fixed' => 0,
            'businesses_without_owner_id' => 0,
            'orphan_owner_ids' => 0,
        ];

        Business::query()->withTrashed()->chunkById(200, function ($chunk) use (&$stats) {
            foreach ($chunk as $business) {
                $stats['businesses_scanned']++;

                $ownerId = (string) ($business->owner_id ?? '');
                if ($ownerId === '') {
                    $stats['businesses_without_owner_id']++;

                    continue;
                }

                // Verificar que el owner exista como user.
                $userExists = DB::table('users')->where('id', $ownerId)->exists();
                if (! $userExists) {
                    $stats['orphan_owner_ids']++;

                    continue;
                }

                $pivot = DB::table('user_business')
                    ->where('user_id', $ownerId)
                    ->where('business_id', $business->id)
                    ->first();

                if ($pivot === null) {
                    DB::table('user_business')->insert([
                        'user_id' => $ownerId,
                        'business_id' => $business->id,
                        'role' => 'owner',
                        'is_active' => true,
                        'joined_at' => now(),
                        'created_at' => now(),
                        'updated_at' => now(),
                    ]);
                    $stats['pivots_created']++;

                    continue;
                }

                $updates = [];
                if ($pivot->role !== 'owner') {
                    $updates['role'] = 'owner';
                    $stats['roles_fixed']++;
                }
                if ((int) $pivot->is_active !== 1) {
                    $updates['is_active'] = true;
                    $stats['pivots_reactivated']++;
                }

                if (! empty($updates)) {
                    $updates['updated_at'] = now();
                    DB::table('user_business')
                        ->where('id', $pivot->id)
                        ->update($updates);
                } else {
                    $stats['owners_ok']++;
                }
            }
        });

        $this->command?->info('Backfill de owners completado.');
        foreach ($stats as $k => $v) {
            $this->command?->line("  {$k}: {$v}");
        }
    }
}
