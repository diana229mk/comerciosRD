<?php

namespace App\Filament\Widgets;

use App\Filament\Resources\ProductResource;
use App\Models\Product;
use Filament\Widgets\StatsOverviewWidget as BaseWidget;
use Filament\Widgets\StatsOverviewWidget\Stat;

class LowStockWidget extends BaseWidget
{
    protected static ?int $sort = 3;

    protected static ?string $pollingInterval = '60s';

    protected int|string|array $columnSpan = 1;

    public static function canView(): bool
    {
        if (! \App\Support\DashboardAccess::allowed()) {
            return false;
        }

        $tenant = filament()->getTenant();

        return $tenant ? $tenant->hasModule('products') : false;
    }

    protected function getStats(): array
    {
        $base = Product::query()
            ->where('is_active', true)
            ->where('track_stock', true);

        $lowStock = (clone $base)
            ->whereColumn('stock', '<=', 'min_stock')
            ->count();

        $outOfStock = (clone $base)
            ->where('stock', '<=', 0)
            ->count();

        $stat = Stat::make('Stock bajo', (string) $lowStock)
            ->description($outOfStock.' sin stock')
            ->descriptionIcon('heroicon-m-exclamation-triangle')
            ->color($lowStock > 0 ? 'warning' : 'success');

        try {
            $stat->url(ProductResource::getUrl('index'));
        } catch (\Throwable) {
            // Sin URL si el tenant/ruta no está disponible
        }

        return [$stat];
    }
}
