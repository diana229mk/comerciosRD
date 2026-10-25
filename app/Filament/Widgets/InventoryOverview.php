<?php

namespace App\Filament\Widgets;

use App\Models\Product;
use Filament\Facades\Filament;
use Filament\Widgets\StatsOverviewWidget as BaseWidget;
use Filament\Widgets\StatsOverviewWidget\Stat;
use Illuminate\Support\Facades\DB;

class InventoryOverview extends BaseWidget
{
    protected static ?int $sort = 3;

    protected static ?string $pollingInterval = '60s';

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
        $totalProducts = Product::query()
            ->where('is_active', true)
            ->count();

        $lowStock = Product::query()
            ->where('is_active', true)
            ->where('track_stock', true)
            ->where('stock', '>', 0)
            ->whereColumn('stock', '<=', 'min_stock')
            ->count();

        $outOfStock = Product::query()
            ->where('is_active', true)
            ->where('track_stock', true)
            ->where('stock', '<=', 0)
            ->count();

        $inventoryValue = (float) (Product::query()
            ->where('is_active', true)
            ->where('track_stock', true)
            ->select(DB::raw('COALESCE(SUM(stock * cost), 0) as total'))
            ->value('total') ?? 0);

        return [
            Stat::make('Productos Activos', $totalProducts)
                ->description('Total de productos en inventario')
                ->descriptionIcon('heroicon-m-cube')
                ->color('primary'),

            Stat::make('Stock Bajo', $lowStock)
                ->description('Productos por debajo del mínimo')
                ->descriptionIcon('heroicon-m-exclamation-triangle')
                ->color($lowStock > 0 ? 'warning' : 'success'),

            Stat::make('Sin Stock', $outOfStock)
                ->description('Productos agotados')
                ->descriptionIcon('heroicon-m-x-circle')
                ->color($outOfStock > 0 ? 'danger' : 'success'),

            Stat::make('Valor del Inventario', 'RD$ '.number_format($inventoryValue, 2))
                ->description('Valor total al costo')
                ->descriptionIcon('heroicon-m-currency-dollar')
                ->color('success'),
        ];
    }
}
