<?php

namespace App\Filament\Widgets;

use App\Models\Business;
use App\Models\SaleItem;
use Filament\Widgets\ChartWidget;

class RetailTopColorsChart extends ChartWidget
{
    protected static ?string $heading = 'Top colores vendidos';

    protected static ?string $description = 'Últimos 30 días';

    protected static ?string $maxHeight = '280px';

    protected int|string|array $columnSpan = 1;

    protected static ?int $sort = 8;

    protected static ?string $pollingInterval = '60s';

    public static function canView(): bool
    {
        if (! \App\Support\DashboardAccess::allowed()) {
            return false;
        }

        $tenant = filament()->getTenant();

        return $tenant instanceof Business
            && $tenant->isRetailStore()
            && $tenant->hasModule('sales');
    }

    protected function getData(): array
    {
        $start = now()->subDays(30)->startOfDay();
        $end = now()->endOfDay();

        $rows = SaleItem::query()
            ->with('variant:id,attributes')
            ->whereNotNull('variant_id')
            ->whereHas('sale', function ($query) use ($start, $end): void {
                $query->completed()
                    ->where(function ($q) use ($start, $end): void {
                        $q->whereBetween('sale_date', [$start, $end])
                            ->orWhereBetween('sold_at', [$start, $end]);
                    });
            })
            ->get();

        $colors = [];
        foreach ($rows as $row) {
            $attributes = $row->variant?->attributes ?? [];
            $color = trim((string) ($attributes['color'] ?? 'N/D')) ?: 'N/D';
            $colors[$color] = ($colors[$color] ?? 0.0) + (float) $row->quantity;
        }

        $top = collect($colors)->sortDesc()->take(8);
        $palette = ['#111827', '#2563EB', '#059669', '#D97706', '#DC2626', '#7C3AED', '#0891B2', '#BE185D'];

        return [
            'datasets' => [[
                'label' => 'Unidades',
                'data' => $top->values()->all(),
                'backgroundColor' => array_slice($palette, 0, max(1, $top->count())),
                'borderRadius' => 6,
            ]],
            'labels' => $top->keys()->all(),
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
