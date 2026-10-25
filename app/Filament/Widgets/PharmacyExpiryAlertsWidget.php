<?php

namespace App\Filament\Widgets;

use App\Filament\Concerns\InteractsWithTenantDashboard;
use App\Models\Product;
use App\Models\ProductBatch;
use App\Support\DashboardWidgetRegistry;
use Filament\Facades\Filament;
use Filament\Widgets\StatsOverviewWidget as BaseWidget;
use Filament\Widgets\StatsOverviewWidget\Stat;

/**
 * Alertas de vencimiento por tramos (30/60/90 días) — solo Farmacia.
 *
 * Muestra 4 tarjetas:
 *  - Vencidos (rojo)
 *  - ≤ 30 días (rojo)
 *  - ≤ 60 días (naranja/warning)
 *  - ≤ 90 días (amarillo/warning)
 *
 * Fuente de datos:
 *  - Si el negocio usa lotes → conteos sobre product_batches (available).
 *  - Si no → conteos sobre products.expires_at.
 */
class PharmacyExpiryAlertsWidget extends BaseWidget
{
    use InteractsWithTenantDashboard;

    protected static ?int $sort = 11;

    protected static ?string $pollingInterval = '120s';

    protected int|string|array $columnSpan = 'full';

    protected static function requiredModules(): array
    {
        return ['products'];
    }

    protected static function widgetKey(): ?string
    {
        return DashboardWidgetRegistry::PHARMACY_EXPIRY_ALERTS;
    }

    public static function canView(): bool
    {
        if (! \App\Support\DashboardAccess::allowed()) {
            return false;
        }

        $tenant = Filament::getTenant();
        if (! $tenant || ! method_exists($tenant, 'isPharmacy') || ! $tenant->isPharmacy()) {
            return false;
        }

        return parent::canView();
    }

    protected function getStats(): array
    {
        [$expired, $d30, $d60, $d90] = $this->counts();

        $totalBrackets = $d30 + $d60 + $d90;

        return [
            Stat::make('Vencidos', (string) $expired)
                ->description($expired > 0
                    ? 'Productos/lotes ya vencidos — bloquean venta'
                    : 'Sin productos vencidos')
                ->descriptionIcon('heroicon-m-x-circle')
                ->color($expired > 0 ? 'danger' : 'success')
                ->chart($this->miniChart($expired)),

            Stat::make('≤ 30 días', (string) $d30)
                ->description('Vencen en el próximo mes')
                ->descriptionIcon('heroicon-m-exclamation-triangle')
                ->color($d30 > 0 ? 'danger' : 'success')
                ->chart($this->miniChart($d30)),

            Stat::make('≤ 60 días', (string) $d60)
                ->description('Vencen entre 31 y 60 días')
                ->descriptionIcon('heroicon-m-clock')
                ->color($d60 > 0 ? 'warning' : 'success')
                ->chart($this->miniChart($d60)),

            Stat::make('≤ 90 días', (string) $d90)
                ->description($totalBrackets > 0
                    ? "Total próximos: {$totalBrackets}"
                    : 'Sin próximos a vencer')
                ->descriptionIcon('heroicon-m-calendar')
                ->color($d90 > 0 ? 'warning' : 'success')
                ->chart($this->miniChart($d90)),
        ];
    }

    /**
     * @return array{int,int,int,int} [vencidos, ≤30, 31-60, 61-90]
     */
    protected function counts(): array
    {
        $tenant = Filament::getTenant();
        if (! $tenant) {
            return [0, 0, 0, 0];
        }

        $usesBatches = ProductBatch::query()->exists();

        if ($usesBatches) {
            return $this->countsFromBatches();
        }

        return $this->countsFromProducts();
    }

    /**
     * @return array{int,int,int,int}
     */
    protected function countsFromBatches(): array
    {
        $today = now()->toDateString();
        $d30 = now()->addDays(30)->toDateString();
        $d60 = now()->addDays(60)->toDateString();
        $d90 = now()->addDays(90)->toDateString();

        $base = ProductBatch::query()
            ->available()
            ->whereNotNull('expires_at');

        $expired = (clone $base)->whereDate('expires_at', '<', $today)->count();

        $le30 = (clone $base)
            ->whereDate('expires_at', '>=', $today)
            ->whereDate('expires_at', '<=', $d30)
            ->count();

        $bt3160 = (clone $base)
            ->whereDate('expires_at', '>', $d30)
            ->whereDate('expires_at', '<=', $d60)
            ->count();

        $bt6190 = (clone $base)
            ->whereDate('expires_at', '>', $d60)
            ->whereDate('expires_at', '<=', $d90)
            ->count();

        return [$expired, $le30, $bt3160, $bt6190];
    }

    /**
     * @return array{int,int,int,int}
     */
    protected function countsFromProducts(): array
    {
        $today = now()->toDateString();
        $d30 = now()->addDays(30)->toDateString();
        $d60 = now()->addDays(60)->toDateString();
        $d90 = now()->addDays(90)->toDateString();

        $base = Product::query()
            ->where('is_active', true)
            ->whereNotNull('expires_at');

        $expired = (clone $base)->whereDate('expires_at', '<', $today)->count();

        $le30 = (clone $base)
            ->whereDate('expires_at', '>=', $today)
            ->whereDate('expires_at', '<=', $d30)
            ->count();

        $bt3160 = (clone $base)
            ->whereDate('expires_at', '>', $d30)
            ->whereDate('expires_at', '<=', $d60)
            ->count();

        $bt6190 = (clone $base)
            ->whereDate('expires_at', '>', $d60)
            ->whereDate('expires_at', '<=', $d90)
            ->count();

        return [$expired, $le30, $bt3160, $bt6190];
    }

    /**
     * Chart decorativo proporcional al conteo.
     *
     * @return list<int>
     */
    protected function miniChart(int $value): array
    {
        if ($value <= 0) {
            return [0, 0, 0, 0, 0, 0, 0];
        }

        $max = max(1, $value);

        return [
            max(0, (int) round($max * 0.2)),
            max(0, (int) round($max * 0.4)),
            max(0, (int) round($max * 0.3)),
            max(0, (int) round($max * 0.6)),
            max(0, (int) round($max * 0.7)),
            max(0, (int) round($max * 0.85)),
            $max,
        ];
    }
}
