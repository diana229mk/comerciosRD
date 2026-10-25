<?php

namespace App\Filament\Master\Widgets;

use App\Services\SubscriptionBillingService;
use Filament\Widgets\ChartWidget;

class SubscriptionsByPlan extends ChartWidget
{
    protected static ?string $heading = 'Suscripciones por plan';

    protected static ?int $sort = 2;

    protected function getData(): array
    {
        $rows = app(SubscriptionBillingService::class)->byPlan();

        return [
            'datasets' => [
                [
                    'label' => 'Negocios',
                    'data' => $rows->pluck('businesses')->values()->all(),
                    'backgroundColor' => ['#3b82f6', '#10b981', '#f59e0b'],
                ],
            ],
            'labels' => $rows->pluck('label')->values()->all(),
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
                'legend' => ['position' => 'bottom'],
            ],
        ];
    }
}
