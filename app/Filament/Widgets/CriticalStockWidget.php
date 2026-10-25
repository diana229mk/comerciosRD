<?php

namespace App\Filament\Widgets;

use App\Filament\Concerns\InteractsWithTenantDashboard;
use App\Filament\Resources\ProductResource;
use App\Models\Product;
use App\Support\DashboardWidgetRegistry;
use Filament\Tables;
use Filament\Tables\Table;
use Filament\Widgets\TableWidget as BaseWidget;

class CriticalStockWidget extends BaseWidget
{
    use InteractsWithTenantDashboard;

    protected static ?string $heading = 'Stock crítico';

    protected static ?int $sort = 10;

    protected int|string|array $columnSpan = [
        'default' => 1,
        'md'      => 1,
        'xl'      => 2,
    ];

    protected static ?string $pollingInterval = '60s';

    protected static function requiredModules(): array
    {
        return ['products'];
    }

    protected static function widgetKey(): ?string
    {
        return DashboardWidgetRegistry::CRITICAL_STOCK;
    }

    public function table(Table $table): Table
    {
        return $table
            ->query(
                Product::query()
                    ->lowStock()
                    ->active()
                    ->orderByRaw('(stock - min_stock) asc')
                    ->orderBy('stock')
            )
            ->defaultPaginationPageOption(5)
            ->paginated([5])
            ->columns([
                Tables\Columns\TextColumn::make('name')
                    ->label('Producto')
                    ->searchable(false)
                    ->limit(28)
                    ->weight('medium'),
                Tables\Columns\TextColumn::make('stock')
                    ->label('Stock')
                    ->alignEnd()
                    ->formatStateUsing(fn ($state, Product $record) => number_format((float) $state, 0).' '.$record->unit)
                    ->color('danger')
                    ->weight('bold'),
                Tables\Columns\TextColumn::make('min_stock')
                    ->label('Mín.')
                    ->alignEnd()
                    ->formatStateUsing(fn ($state) => number_format((float) $state, 0)),
            ])
            ->actions([
                Tables\Actions\Action::make('view')
                    ->label('Ver')
                    ->icon('heroicon-m-eye')
                    ->url(fn (Product $record) => ProductResource::getUrl('view', ['record' => $record]))
                    ->visible(fn () => class_exists(ProductResource::class)),
            ])
            ->emptyStateHeading('Sin alertas de stock')
            ->emptyStateDescription('Todos los productos están por encima del mínimo.')
            ->emptyStateIcon('heroicon-o-check-circle');
    }
}
