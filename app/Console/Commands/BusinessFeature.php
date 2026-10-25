<?php

namespace App\Console\Commands;

use App\Models\Business;
use Illuminate\Console\Command;

/**
 * Activa o desactiva una feature JSON en un negocio.
 *
 * Ejemplos:
 *   php artisan business:feature ams strict_branch_stock --enable
 *   php artisan business:feature ams strict_branch_stock --disable
 *   php artisan business:feature ams strict_branch_stock (muestra estado)
 *
 * Introducido en FASE S3a+ para poder activar restricción FEFO por sucursal
 * negocio por negocio, sin exponer un toggle nuevo en el panel master.
 *
 * FASE H4 · Ahora valida contra whitelist de features conocidas (ver
 * self::KNOWN_FEATURES) y sugiere corrección si hay typo. Para listar
 * features disponibles: `php artisan business:features`.
 */
class BusinessFeature extends Command
{
    protected $signature = 'business:feature
        {slug : Slug del negocio (ej: ams, colmado-los-alcarrizos)}
        {feature : Nombre de la feature (ej: strict_branch_stock)}
        {--enable : Activa la feature}
        {--disable : Desactiva la feature}
        {--force : Permite operar sobre features fuera de la whitelist (uso master)}';

    protected $description = 'Activa, desactiva o consulta un flag del array features de un negocio.';

    /**
     * Features registradas oficialmente. Cualquier otra requiere --force.
     *
     * @var array<string, string>
     */
    public const KNOWN_FEATURES = [
        'strict_branch_stock' => 'S3a+ · POS descuenta FEFO sólo de lotes de la sucursal + fondo común (NULL).',
        'strict_branch_scope' => 'S4a+S4c · Aísla lecturas y escrituras por sucursal para roles no-admin (cajero, vendedor, inventario, contable).',
    ];

    public function handle(): int
    {
        $slug = (string) $this->argument('slug');
        $feature = (string) $this->argument('feature');

        /** @var ?Business $business */
        $business = Business::query()->where('slug', $slug)->first();
        if (! $business) {
            $this->error("Negocio con slug '{$slug}' no encontrado.");

            return self::FAILURE;
        }

        // FASE H4: guarda anti-typo. Rechaza features desconocidas salvo --force.
        if (! array_key_exists($feature, self::KNOWN_FEATURES) && ! $this->option('force')) {
            $this->error("Feature '{$feature}' no está registrada.");

            $suggestion = $this->suggestClosestFeature($feature);
            if ($suggestion !== null) {
                $this->warn("¿Quisiste decir '{$suggestion}'?");
            }

            $this->line('');
            $this->line('Features registradas:');
            foreach (self::KNOWN_FEATURES as $name => $desc) {
                $this->line("  - <fg=cyan>{$name}</>: {$desc}");
            }
            $this->line('');
            $this->comment('Usa --force para operar sobre una feature fuera de esta lista.');

            return self::INVALID;
        }

        $features = is_array($business->features) ? $business->features : [];
        $current = (bool) ($features[$feature] ?? false);

        if ($this->option('enable') && $this->option('disable')) {
            $this->error('No se puede usar --enable y --disable a la vez.');

            return self::INVALID;
        }

        if (! $this->option('enable') && ! $this->option('disable')) {
            $this->info("Negocio: {$business->name} ({$business->slug})");
            $this->line("Feature '{$feature}': ".($current ? '<fg=green>ENABLED</>' : '<fg=yellow>DISABLED</>'));

            return self::SUCCESS;
        }

        $features[$feature] = (bool) $this->option('enable');
        $business->forceFill(['features' => $features])->save();

        $this->info("Negocio: {$business->name} ({$business->slug})");
        $this->line("Feature '{$feature}': ".($current ? 'ENABLED' : 'DISABLED').' → '.($features[$feature] ? '<fg=green>ENABLED</>' : '<fg=yellow>DISABLED</>'));

        return self::SUCCESS;
    }

    /**
     * Devuelve la feature registrada más parecida al string dado (Levenshtein).
     * null si no hay ninguna razonablemente cerca (distancia > 5).
     */
    private function suggestClosestFeature(string $input): ?string
    {
        $best = null;
        $bestDistance = PHP_INT_MAX;

        foreach (array_keys(self::KNOWN_FEATURES) as $known) {
            $distance = levenshtein(strtolower($input), strtolower($known));
            if ($distance < $bestDistance) {
                $bestDistance = $distance;
                $best = $known;
            }
        }

        return $bestDistance <= 5 ? $best : null;
    }
}

