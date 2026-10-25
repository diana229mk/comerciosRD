<?php

namespace App\Console\Commands;

use App\Models\Business;
use Illuminate\Console\Command;

/**
 * FASE H4 · Lista features registradas + su estado por negocio.
 *
 * Ejemplos:
 *   php artisan business:features                    (lista features registradas)
 *   php artisan business:features --for=ams          (estado en un negocio específico)
 *   php artisan business:features --all-businesses   (matriz feature × negocio)
 */
class BusinessFeatures extends Command
{
    protected $signature = 'business:features
        {--for= : Slug de un negocio para mostrar su estado}
        {--all-businesses : Muestra matriz feature × negocio (todos los negocios)}';

    protected $description = 'Lista las feature flags registradas oficialmente y (opcional) su estado por negocio.';

    public function handle(): int
    {
        $known = BusinessFeature::KNOWN_FEATURES;

        if ($known === []) {
            $this->warn('No hay features registradas en BusinessFeature::KNOWN_FEATURES.');

            return self::SUCCESS;
        }

        $slug = $this->option('for');
        $allBusinesses = (bool) $this->option('all-businesses');

        if ($slug !== null) {
            return $this->showForBusiness((string) $slug, $known);
        }

        if ($allBusinesses) {
            return $this->showMatrix($known);
        }

        $this->info('Features registradas:');
        foreach ($known as $name => $desc) {
            $this->line('');
            $this->line("  <fg=cyan;options=bold>{$name}</>");
            $this->line("    {$desc}");
        }

        $this->line('');
        $this->comment('Consultas útiles:');
        $this->line('  php artisan business:features --for=<slug>');
        $this->line('  php artisan business:features --all-businesses');
        $this->line('  php artisan business:feature <slug> <feature> --enable|--disable');

        return self::SUCCESS;
    }

    /**
     * @param  array<string, string>  $known
     */
    private function showForBusiness(string $slug, array $known): int
    {
        $business = Business::query()->where('slug', $slug)->first();
        if (! $business) {
            $this->error("Negocio con slug '{$slug}' no encontrado.");

            return self::FAILURE;
        }

        $features = is_array($business->features) ? $business->features : [];

        $this->info("Negocio: {$business->name} ({$business->slug})");
        $this->line('');

        $rows = [];
        foreach ($known as $name => $desc) {
            $enabled = (bool) ($features[$name] ?? false);
            $rows[] = [
                $name,
                $enabled ? '✓ ENABLED' : '✗ disabled',
            ];
        }

        $this->table(['Feature', 'Estado'], $rows);

        $unknown = array_diff(array_keys($features), array_keys($known));
        if ($unknown !== []) {
            $this->line('');
            $this->warn('Features NO registradas encontradas en este negocio (posibles typos):');
            foreach ($unknown as $u) {
                $this->line("  - {$u} = ".(($features[$u] ?? false) ? 'true' : 'false'));
            }
        }

        return self::SUCCESS;
    }

    /**
     * @param  array<string, string>  $known
     */
    private function showMatrix(array $known): int
    {
        $businesses = Business::query()
            ->orderBy('slug')
            ->get(['id', 'name', 'slug', 'features']);

        if ($businesses->isEmpty()) {
            $this->warn('No hay negocios registrados.');

            return self::SUCCESS;
        }

        $headers = ['Negocio (slug)', ...array_keys($known)];
        $rows = [];

        foreach ($businesses as $b) {
            $features = is_array($b->features) ? $b->features : [];
            $row = [$b->slug];
            foreach (array_keys($known) as $name) {
                $row[] = ($features[$name] ?? false) ? '✓' : '·';
            }
            $rows[] = $row;
        }

        $this->table($headers, $rows);
        $this->line('  ✓ = habilitada · = deshabilitada');

        return self::SUCCESS;
    }
}
