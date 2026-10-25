<?php

namespace App\Filament\Widgets;

use App\Filament\Resources\ProductResource;
use App\Models\Product;
use Filament\Facades\Filament;
use Filament\Tables;
use Filament\Tables\Table;
use Filament\Widgets\TableWidget as BaseWidget;

class LowStock extends BaseWidget
{
    protected static ?int $sort = 4;

    protected int|string|array $columnSpan = 'full';

    protected static ?string $heading = 'Productos con Stock Bajo o Agotado';

    protected static ?string $pollingInterval = '60s';

    public static function canView(): bool
    {
        if (! \App\Support\DashboardAccess::allowed()) {
            return false;
        }

        $tenant = filament()->getTenant();

        return $tenant ? $tenant->hasModule('products') : false;
    }

    public function table(Table $table): Table
    {
        return $table
            ->query(
                Product::query()
                    ->where('is_active', true)
                    ->where('track_stock', true)
                    ->whereColumn('stock', '<=', 'min_stock')
                    ->with('category')
                    // Más críticos primero: mayor faltante (min_stock - stock)
                    ->orderByRaw('(min_stock - stock) DESC')
                    ->orderBy('stock')
                    ->limit(10)
            )
            ->columns([
                Tables\Columns\TextColumn::make('name')
                    ->label('Producto')
                    ->searchable()
                    ->weight('bold')
                    ->description(fn (Product $record): ?string => filled($record->barcode)
                        ? 'Código: '.$record->barcode
                        : (filled($record->sku) ? 'SKU: '.$record->sku : null)),

                Tables\Columns\TextColumn::make('category.full_name')
                    ->label('Categoría')
                    ->badge()
                    ->color('primary')
                    ->placeholder('Sin categoría')
                    ->toggleable(),

                Tables\Columns\TextColumn::make('stock')
                    ->label('Stock Actual')
                    ->sortable()
                    ->weight('bold')
                    ->color(fn (Product $record): string => (float) $record->stock <= 0 ? 'danger' : 'warning')
                    ->icon(fn (Product $record): string => (float) $record->stock <= 0
                        ? 'heroicon-m-x-circle'
                        : 'heroicon-m-exclamation-triangle')
                    ->formatStateUsing(fn ($state, Product $record): string => number_format((float) $state, 0).' '.($record->unit ?? '')),

                Tables\Columns\TextColumn::make('min_stock')
                    ->label('Stock Mínimo')
                    ->formatStateUsing(fn ($state, Product $record): string => number_format((float) $state, 0).' '.($record->unit ?? '')),

                Tables\Columns\TextColumn::make('diferencia')
                    ->label('Faltante')
                    ->state(fn (Product $record): float => max(0, (float) $record->min_stock - (float) $record->stock))
                    ->badge()
                    ->color('danger')
                    ->formatStateUsing(fn ($state, Product $record): string => number_format((float) $state, 0).' '.($record->unit ?? '')),

                Tables\Columns\TextColumn::make('price')
                    ->label('Precio')
                    ->money('DOP')
                    ->toggleable(isToggledHiddenByDefault: true),
            ])
            ->actions([
                Tables\Actions\Action::make('ajustar')
                    ->label('Ajustar')
                    ->icon('heroicon-o-adjustments-horizontal')
                    ->color('warning')
                    ->url(fn (Product $record): string => ProductResource::getUrl('edit', [
                        'tenant' => Filament::getTenant(),
                        'record' => $record,
                    ])),

                Tables\Actions\Action::make('ver')
                    ->label('Ver')
                    ->icon('heroicon-o-eye')
                    ->url(fn (Product $record): string => ProductResource::getUrl('view', [
                        'tenant' => Filament::getTenant(),
                        'record' => $record,
                    ])),
            ])
            ->paginated(false)
            ->emptyStateHeading('¡Excelente! No hay productos con stock bajo')
            ->emptyStateDescription('Todos tus productos tienen stock suficiente.')
            ->emptyStateIcon('heroicon-o-check-circle');
    }
}
