<?php

namespace App\Filament\Widgets;

use App\Filament\Concerns\InteractsWithTenantDashboard;
use App\Support\DashboardWidgetRegistry;
use Filament\Widgets\ChartWidget;

/**
 * Comparativa por horario / turno (útil en cafeterías y colmados).
 */
class SalesByHourChart extends ChartWidget
{
    use InteractsWithTenantDashboard;

    protected static ?string $heading = 'Ventas por horario';

    protected static ?string $description = 'Promedio de actividad por hora · últimos 14 días';

    protected static ?string $maxHeight = '280px';

    protected static ?int $sort = 23;

    protected int|string|array $columnSpan = [
        'default' => 1,
        'md'      => 2,
        'xl'      => 2,
    ];

    protected static ?string $pollingInterval = '120s';

    protected static function requiredModules(): array
    {
        return ['sales'];
    }

    protected static function widgetKey(): ?string
    {
        return DashboardWidgetRegistry::SALES_BY_HOUR;
    }

    protected function getData(): array
    {
        $metrics = $this->metrics();
        $series = $metrics?->salesByHour() ?? ['labels' => [], 'values' => []];

        // Resaltar franjas de turno típicas RD: mañana / tarde / noche
        $colors = [];
        foreach (range(0, 23) as $h) {
            $colors[] = match (true) {
                $h >= 6 && $h < 12  => 'rgba(14, 159, 110, 0.85)',  // mañana
                $h >= 12 && $h < 18 => 'rgba(26, 86, 219, 0.85)',   // tarde
                $h >= 18 && $h < 23 => 'rgba(245, 158, 11, 0.85)',  // noche
                default             => 'rgba(100, 116, 139, 0.55)', // madrugada
            };
        }

        return [
            'datasets' => [
                [
                    'label'           => 'Ventas (RD$)',
                    'data'            => $series['values'],
                    'backgroundColor' => $colors,
                    'borderRadius'    => 4,
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
            'plugins' => [
                'legend' => ['display' => false],
            ],
            'scales' => [
                'y' => ['beginAtZero' => true],
            ],
        ];
    }
}
