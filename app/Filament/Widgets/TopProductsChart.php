<?php

namespace App\Filament\Widgets;

use App\Filament\Concerns\InteractsWithTenantDashboard;
use App\Support\DashboardWidgetRegistry;
use Filament\Widgets\ChartWidget;

class TopProductsChart extends ChartWidget
{
    use InteractsWithTenantDashboard;

    protected static ?string $heading = 'Top 10 productos más vendidos';

    protected static ?string $description = 'Por cantidad · mes actual';

    protected static ?string $maxHeight = '280px';

    protected static ?int $sort = 21;

    protected int|string|array $columnSpan = [
        'default' => 1,
        'md'      => 2,
        'xl'      => 2,
    ];

    protected static ?string $pollingInterval = '60s';

    protected static function requiredModules(): array
    {
        return ['sales', 'orders'];
    }

    protected static function widgetKey(): ?string
    {
        return DashboardWidgetRegistry::TOP_PRODUCTS;
    }

    protected function getData(): array
    {
        $metrics = $this->metrics();
        $series = $metrics?->topProducts(10) ?? ['labels' => [], 'values' => []];

        $palette = [
            '#1A56DB', '#0E9F6E', '#F59E0B', '#EF4444', '#8B5CF6',
            '#06B6D4', '#EC4899', '#84CC16', '#F97316', '#64748B',
        ];

        $colors = [];
        foreach (array_keys($series['values']) as $i) {
            $colors[] = $palette[$i % count($palette)];
        }

        return [
            'datasets' => [
                [
                    'label'           => 'Cantidad',
                    'data'            => $series['values'],
                    'backgroundColor' => $colors,
                    'borderRadius'    => 6,
                ],
            ],
            'labels' => $series['labels'],
        ];
    }

    protected function getType(): string
    {
        return 'bar';
    }

    protected function getOptions(): array
    {
        return [
            'indexAxis' => 'y',
            'plugins'   => [
                'legend' => ['display' => false],
            ],
            'scales' => [
                'x' => ['beginAtZero' => true],
            ],
        ];
    }
}
