<?php

namespace App\Filament\Widgets;

use App\Models\SaleItem;
use Filament\Tables;
use Filament\Tables\Table;
use Filament\Widgets\TableWidget as BaseWidget;
use Illuminate\Support\Facades\DB;

class TopProductsWidget extends BaseWidget
{
    protected static ?int $sort = 6;

    protected int|string|array $columnSpan = 'full';

    protected static ?string $heading = 'Top productos del mes';

    protected static ?string $pollingInterval = '60s';

    public static function canView(): bool
    {
        if (! \App\Support\DashboardAccess::allowed()) {
            return false;
        }

        $tenant = filament()->getTenant();

        return $tenant ? $tenant->hasModule('sales') : false;
    }

    public function table(Table $table): Table
    {
        $monthStart = now()->startOfMonth()->toDateTimeString();
        $monthEnd = now()->endOfMonth()->toDateTimeString();

        return $table
            ->query(
                SaleItem::query()
                    ->select([
                        DB::raw('MIN(sale_items.id) as id'),
                        'sale_items.product_name',
                        DB::raw('SUM(sale_items.quantity) as total_qty'),
                        DB::raw('SUM(COALESCE(sale_items.line_total, sale_items.subtotal, 0)) as total_revenue'),
                    ])
                    ->join('sales', function ($join): void {
                        $join->on('sale_items.sale_id', '=', 'sales.id')
                            ->whereNull('sales.deleted_at');
                    })
                    ->whereIn('sales.status', ['completada', 'completed'])
                    ->where(function ($q) use ($monthStart, $monthEnd): void {
                        $q->whereBetween('sales.sale_date', [$monthStart, $monthEnd])
                            ->orWhereBetween('sales.sold_at', [$monthStart, $monthEnd]);
                    })
                    ->groupBy('sale_items.product_name')
                    ->orderByDesc('total_qty')
                    ->limit(5)
            )
            ->columns([
                Tables\Columns\TextColumn::make('product_name')
                    ->label('Producto')
                    ->weight('bold'),

                Tables\Columns\TextColumn::make('total_qty')
                    ->label('Cantidad')
                    ->alignRight()
                    ->formatStateUsing(fn ($state) => number_format((float) $state, 0)),

                Tables\Columns\TextColumn::make('total_revenue')
                    ->label('Ingresos')
                    ->money('DOP')
                    ->alignRight(),
            ])
            ->paginated(false);
    }
}
