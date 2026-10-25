<?php

namespace App\Filament\Widgets;

use App\Services\SalesDailySummaryService;
use Filament\Widgets\StatsOverviewWidget as BaseWidget;
use Filament\Widgets\StatsOverviewWidget\Stat;

class SalesMonthWidget extends BaseWidget
{
    protected static bool $isLazy = false;

    protected static ?int $sort = 2;

    protected static ?string $pollingInterval = '60s';

    protected int|string|array $columnSpan = 1;

    public static function canView(): bool
    {
        if (! \App\Support\DashboardAccess::allowed()) {
            return false;
        }

        return true;
    }

    protected function getStats(): array
    {
        $start = now()->startOfMonth();
        $end = now()->endOfMonth();

        $totals = app(SalesDailySummaryService::class)->totalsBetween($start, $end);
        $total = $totals['total'];
        $count = $totals['tickets'];

        return [
            Stat::make('Ventas del mes', 'RD$ '.number_format($total, 2))
                ->description($count.' '.($count === 1 ? 'venta este mes' : 'ventas este mes'))
                ->descriptionIcon('heroicon-m-shopping-cart')
                ->color('primary'),
        ];
    }
}
