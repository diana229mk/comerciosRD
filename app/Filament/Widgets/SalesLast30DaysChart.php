<?php

namespace App\Filament\Widgets;

use App\Filament\Concerns\InteractsWithTenantDashboard;
use App\Support\DashboardWidgetRegistry;
use Filament\Widgets\ChartWidget;

class SalesLast30DaysChart extends ChartWidget
{
    use InteractsWithTenantDashboard;

    protected static ?string $heading = 'Evolución de ventas · 30 días';

    protected static ?string $description = 'Total diario de ventas completadas (RD$)';

    protected static ?string $maxHeight = '280px';

    protected static ?int $sort = 20;

    protected int|string|array $columnSpan = [
        'default' => 1,
        'md'      => 2,
        'xl'      => 2,
    ];

    protected static ?string $pollingInterval = '60s';

    protected static string $color = 'primary';

    protected static function requiredModules(): array
    {
        return ['sales'];
    }

    protected static function widgetKey(): ?string
    {
        return DashboardWidgetRegistry::SALES_30D;
    }

    protected function getData(): array
    {
        $metrics = $this->metrics();
        $series = $metrics?->salesLast30Days() ?? ['labels' => [], 'values' => []];

        return [
            'datasets' => [
                [
                    'label'           => 'Ventas (RD$)',
                    'data'            => $series['values'],
                    'fill'            => true,
                    'borderColor'     => '#1A56DB',
                    'backgroundColor' => 'rgba(26, 86, 219, 0.12)',
                    'tension'         => 0.35,
                    'pointRadius'     => 2,
                ],
            ],
            'labels' => $series['labels'],
        ];
    }

    protected function getType(): string
    {
        return 'line';
    }

    protected function getOptions(): array
    {
        return [
            'plugins' => [
                'legend' => ['display' => false],
            ],
            'scales' => [
                'y' => [
                    'beginAtZero' => true,
                ],
            ],
        ];
    }
}

