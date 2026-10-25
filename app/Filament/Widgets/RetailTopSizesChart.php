<?php

namespace App\Filament\Widgets;

use App\Models\Business;
use App\Models\SaleItem;
use Filament\Widgets\ChartWidget;

class RetailTopSizesChart extends ChartWidget
{
    protected static ?string $heading = 'Top tallas vendidas';

    protected static ?string $description = 'Últimos 30 días';

    protected static ?string $maxHeight = '280px';

    protected int|string|array $columnSpan = 1;

    protected static ?int $sort = 7;

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

        $sizes = [];
        foreach ($rows as $row) {
            $attributes = $row->variant?->attributes ?? [];
            $size = trim((string) ($attributes['size'] ?? 'N/D')) ?: 'N/D';
            $sizes[$size] = ($sizes[$size] ?? 0.0) + (float) $row->quantity;
        }

        $top = collect($sizes)->sortDesc()->take(8);

        return [
            'datasets' => [[
                'label' => 'Unidades',
                'data' => $top->values()->all(),
                'backgroundColor' => '#2563EB',
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
