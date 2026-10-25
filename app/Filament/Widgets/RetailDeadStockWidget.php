<?php

namespace App\Filament\Widgets;

use App\Models\Business;
use App\Models\ProductVariant;
use Filament\Tables;
use Filament\Tables\Table;
use Filament\Widgets\TableWidget as BaseWidget;

class RetailDeadStockWidget extends BaseWidget
{
    protected static ?string $heading = 'Stock lento (60+ días sin venta)';

    protected static ?int $sort = 9;

    protected int|string|array $columnSpan = 'full';

    protected static ?string $pollingInterval = '120s';

    public static function canView(): bool
    {
        if (! \App\Support\DashboardAccess::allowed()) {
            return false;
        }

        $tenant = filament()->getTenant();

        return $tenant instanceof Business
            && $tenant->isRetailStore()
            && $tenant->hasModule('variants');
    }

    public function table(Table $table): Table
    {
        $start = now()->subDays(60)->startOfDay();
        $end = now()->endOfDay();

        return $table
            ->query(
                ProductVariant::query()
                    ->with(['product:id,name,collection,season_id', 'product.season:id,name'])
                    ->where('is_active', true)
                    ->where('stock', '>', 0)
                    ->whereHas('product', fn ($query) => $query->where('is_active', true))
                    ->whereDoesntHave('saleItems.sale', function ($query) use ($start, $end): void {
                        $query->completed()
                            ->where(function ($q) use ($start, $end): void {
                                $q->whereBetween('sale_date', [$start, $end])
                                    ->orWhereBetween('sold_at', [$start, $end]);
                            });
                    })
                    ->orderByDesc('stock')
                    ->limit(10)
            )
            ->columns([
                Tables\Columns\TextColumn::make('product.name')
                    ->label('Producto')
                    ->weight('bold')
                    ->searchable(),
                Tables\Columns\TextColumn::make('size')
                    ->label('Talla')
                    ->getStateUsing(fn (ProductVariant $record): string => (string) ($record->attributes['size'] ?? 'N/D')),
                Tables\Columns\TextColumn::make('color')
                    ->label('Color')
                    ->getStateUsing(fn (ProductVariant $record): string => (string) ($record->attributes['color'] ?? 'N/D')),
                Tables\Columns\TextColumn::make('product.collection')
                    ->label('Colección')
                    ->default('Sin colección'),
                Tables\Columns\TextColumn::make('product.season.name')
                    ->label('Temporada')
                    ->default('Sin temporada'),
                Tables\Columns\TextColumn::make('stock')
                    ->label('Stock')
                    ->alignRight()
                    ->formatStateUsing(fn ($state) => number_format((float) $state, 3)),
            ])
            ->paginated(false);
    }
}
