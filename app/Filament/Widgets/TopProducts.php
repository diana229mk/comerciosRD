<?php

namespace App\Filament\Widgets;

use App\Models\OrderItem;
use Filament\Tables;
use Filament\Tables\Table;
use Filament\Widgets\TableWidget as BaseWidget;
use Illuminate\Support\Facades\DB;

class TopProducts extends BaseWidget
{
    protected static ?int $sort = 4;

    protected int|string|array $columnSpan = 'full';

    protected static ?string $heading = 'Productos Más Vendidos (Este Mes)';

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

    public function table(Table $table): Table
    {
        return $table
            ->query(
                OrderItem::query()
                    ->select([
                        // id sintético para TableWidget (requiere PK)
                        DB::raw('MIN(order_items.id) as id'),
                        'order_items.product_id',
                        'order_items.product_name',
                        DB::raw('SUM(order_items.quantity) as total_quantity'),
                        DB::raw('SUM(order_items.subtotal) as total_amount'),
                    ])
                    ->join('orders', function ($join) {
                        $join->on('order_items.order_id', '=', 'orders.id')
                            ->whereNull('orders.deleted_at');
                    })
                    ->where('orders.status', 'completada')
                    ->whereMonth('orders.completed_at', now()->month)
                    ->whereYear('orders.completed_at', now()->year)
                    ->groupBy('order_items.product_id', 'order_items.product_name')
                    ->orderByDesc('total_quantity')
                    ->limit(8)
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
                    ->formatStateUsing(fn ($state) => number_format((float) $state, 0)),

                Tables\Columns\TextColumn::make('total_amount')
                    ->label('Total Vendido')
                    ->money('DOP')
                    ->sortable(),
            ])
            ->paginated(false)
            ->emptyStateHeading('Sin ventas este mes')
            ->emptyStateDescription('Cuando completes órdenes, verás el ranking de productos aquí.')
            ->emptyStateIcon('heroicon-o-chart-bar');
    }
}
