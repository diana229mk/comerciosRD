<?php

namespace App\Filament\Widgets;

use App\Models\Order;
use App\Models\Sale;
use Carbon\Carbon;
use Filament\Widgets\ChartWidget;

/**
 * Placeholder listo para activar en AdminPanelProvider.
 */
class SalesChart extends ChartWidget
{
    protected static ?string $heading = 'Ventas últimos 7 días';

    protected static ?int $sort = 3;

    protected static ?string $maxHeight = '280px';

    protected int|string|array $columnSpan = 'full';

    public static function canView(): bool
    {
        if (! \App\Support\DashboardAccess::allowed()) {
            return false;
        }

        $tenant = filament()->getTenant();

        return $tenant ? $tenant->hasModule('sales') : false;
    }

    protected function getData(): array
    {
        $labels = [];
        $values = [];

        for ($i = 6; $i >= 0; $i--) {
            $day = Carbon::today()->subDays($i);
            $labels[] = $day->format('d/m');

            $sum = (float) Sale::query()
                ->where('status', 'completed')
                ->whereDate('sold_at', $day)
                ->sum('total');

            if ($sum <= 0) {
                $sum = (float) Order::query()
                    ->where('status', 'completada')
                    ->whereDate('completed_at', $day)
                    ->sum('total');
            }

            $values[] = $sum;
        }

        return [
            'datasets' => [
                [
                    'label' => 'Ventas (RD$)',
                    'data' => $values,
                    'borderColor' => '#f59e0b',
                    'backgroundColor' => 'rgba(245, 158, 11, 0.15)',
                    'fill' => true,
                    'tension' => 0.35,
                ],
            ],
            'labels' => $labels,
        ];
    }

    protected function getType(): string
    {
        return 'line';
    }
}
