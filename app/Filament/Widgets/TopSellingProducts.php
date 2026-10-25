<?php

namespace App\Filament\Widgets;

use App\Models\SaleItem;
use App\Support\DashboardPeriod;
use Filament\Tables;
use Filament\Tables\Table;
use Filament\Widgets\Concerns\InteractsWithPageFilters;
use Filament\Widgets\TableWidget as BaseWidget;
use Illuminate\Support\Facades\DB;

/**
 * Ranking de productos por ventas POS del mes actual (sale_items).
 */
class TopSellingProducts extends BaseWidget
{
    use InteractsWithPageFilters;

    protected static ?int $sort = 9;

    protected int|string|array $columnSpan = 'full';

    protected static ?string $heading = 'Productos más vendidos';

    protected static ?string $pollingInterval = '60s';

    public static function canView(): bool
    {
        if (! \App\Support\DashboardAccess::allowed()) {
            return false;
        }

        $tenant = filament()->getTenant();

        return $tenant
            ? ($tenant->hasModule('sales') || $tenant->hasModule('orders'))
            : false;
    }

    public function getTableHeading(): ?string
    {
        $period = DashboardPeriod::fromFilters($this->filters);

        return 'Productos más vendidos · '.$period['label'];
    }

    public function table(Table $table): Table
    {
        $period = DashboardPeriod::fromFilters($this->filters);
        $from = $period['from']->toDateTimeString();
        $to = $period['to']->toDateTimeString();

        return $table
            ->query(
                SaleItem::query()
                    ->select([
                        DB::raw('MIN(sale_items.id) as id'),
                        'sale_items.product_id',
                        'sale_items.product_name',
                        DB::raw('SUM(sale_items.quantity) as total_quantity'),
                        DB::raw('SUM(COALESCE(sale_items.line_total, sale_items.subtotal, 0)) as total_revenue'),
                    ])
                    ->join('sales', function ($join): void {
                        $join->on('sale_items.sale_id', '=', 'sales.id')
                            ->whereNull('sales.deleted_at');
                    })
                    ->whereIn('sales.status', ['completada', 'completed'])
                    ->where(function ($q) use ($from, $to): void {
                        $q->whereBetween('sales.sale_date', [$from, $to])
                            ->orWhereBetween('sales.sold_at', [$from, $to]);
                    })
                    ->groupBy('sale_items.product_id', 'sale_items.product_name')
                    ->orderByDesc('total_quantity')
                    ->limit(10)
            )
            ->columns([
                Tables\Columns\TextColumn::make('product_name')
                    ->label('Producto')
                    ->searchable()
                    ->weight('bold'),

                Tables\Columns\TextColumn::make('total_quantity')
                    ->label('Cantidad Vendida')
                    ->badge()
                    ->color('success')
                    ->formatStateUsing(fn ($state) => number_format((float) $state, 0))
                    ->sortable(),

                Tables\Columns\TextColumn::make('total_revenue')
                    ->label('Ingresos Generados')
                    ->money('DOP')
                    ->sortable()
                    ->color('primary'),
            ])
            ->paginated(false)
            ->emptyStateHeading('No hay ventas en este período')
            ->emptyStateDescription('Cambia Hoy / Semana / Mes o cobra en el POS.')
            ->emptyStateIcon('heroicon-o-chart-bar');
    }
}
