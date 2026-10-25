<?php

namespace App\Filament\Widgets;

use App\Filament\Concerns\InteractsWithTenantDashboard;
use App\Support\DashboardWidgetRegistry;
use Filament\Widgets\ChartWidget;

class ExpensesByCategoryChart extends ChartWidget
{
    use InteractsWithTenantDashboard;

    protected static ?string $heading = 'Distribución de gastos por categoría';

    protected static ?string $description = 'Gastos pagados del mes actual';

    protected static ?string $maxHeight = '280px';

    protected static ?int $sort = 22;

    protected int|string|array $columnSpan = [
        'default' => 1,
        'md'      => 1,
        'xl'      => 2,
    ];

    protected static ?string $pollingInterval = '60s';

    protected static function requiredModules(): array
    {
        return ['expenses'];
    }

    protected static function widgetKey(): ?string
    {
        return DashboardWidgetRegistry::EXPENSES_PIE;
    }

    protected function getData(): array
    {
        $metrics = $this->metrics();
        $series = $metrics?->expensesByCategory() ?? ['labels' => [], 'values' => []];

        if ($series['labels'] === []) {
            $series = [
                'labels' => ['Sin gastos'],
                'values' => [0],
            ];
        }

        $palette = [
            '#1A56DB', '#0E9F6E', '#F59E0B', '#EF4444', '#8B5CF6',
            '#06B6D4', '#EC4899', '#84CC16', '#F97316',
        ];

        $colors = [];
        foreach (array_keys($series['values']) as $i) {
            $colors[] = $palette[$i % count($palette)];
        }

        return [
            'datasets' => [
                [
                    'data'            => $series['values'],
                    'backgroundColor' => $colors,
                    'borderWidth'     => 0,
                ],
            ],
            'labels' => $series['labels'],
        ];
    }

    protected function getType(): string
    {
        return 'doughnut';
    }

    protected function getOptions(): array
    {
        return [
            'plugins' => [
                'legend' => [
                    'position' => 'bottom',
                ],
            ],
            'cutout' => '55%',
        ];
    }
}
