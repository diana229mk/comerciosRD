<?php

namespace App\Filament\Master\Widgets;

use App\Enums\BusinessType;
use App\Models\Business;
use Filament\Widgets\ChartWidget;

class BusinessesByType extends ChartWidget
{
    protected static ?string $heading = 'Cartera por giro (locales, no ventas POS)';

    protected static ?int $sort = 2;

    protected function getData(): array
    {
        $data = Business::query()
            ->selectRaw('type, COUNT(*) as total')
            ->groupBy('type')
            ->orderByDesc('total')
            ->get();

        $labels = [];
        $values = [];

        foreach ($data as $row) {
            $type = $row->type;

            if ($type instanceof BusinessType) {
                $labels[] = $type->label();
            } else {
                $labels[] = BusinessType::tryFrom((string) $type)?->label()
                    ?? ucfirst((string) $type);
            }

            $values[] = (int) $row->total;
        }

        return [
            'datasets' => [
                [
                    'label' => 'Cantidad de Negocios',
                    'data' => $values,
                    'backgroundColor' => [
                        '#3b82f6', // Azul
                        '#10b981', // Verde
                        '#f59e0b', // Amarillo
                        '#ef4444', // Rojo
                        '#8b5cf6', // Morado
                        '#ec4899', // Rosa
                    ],
                ],
            ],
            'labels' => $labels,
        ];
    }

    protected function getType(): string
    {
        return 'pie';
    }

    protected function getOptions(): array
    {
        return [
            'plugins' => [
                'legend' => [
                    'position' => 'bottom',
                ],
            ],
        ];
    }
}
