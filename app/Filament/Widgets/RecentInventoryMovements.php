<?php

namespace App\Filament\Widgets;

use App\Enums\InventoryMovementType;
use App\Models\InventoryMovement;
use Filament\Facades\Filament;
use Filament\Tables;
use Filament\Tables\Table;
use Filament\Widgets\TableWidget as BaseWidget;

class RecentInventoryMovements extends BaseWidget
{
    protected static ?int $sort = 10;

    protected int|string|array $columnSpan = 'full';

    protected static ?string $heading = 'Últimos Movimientos de Inventario';

    protected static ?string $pollingInterval = '60s';

    public static function canView(): bool
    {
        if (! \App\Support\DashboardAccess::allowed()) {
            return false;
        }

        $tenant = filament()->getTenant();

        return $tenant
            ? ($tenant->hasModule('products') || $tenant->hasModule('inventory_movements'))
            : false;
    }

    public function table(Table $table): Table
    {
        return $table
            ->query(
                InventoryMovement::query()
                    ->with(['product', 'creator'])
                    ->latest()
                    ->limit(8)
            )
            ->columns([
                Tables\Columns\TextColumn::make('created_at')
                    ->label('Fecha')
                    ->dateTime('d/m/Y H:i')
                    ->sortable(),

                Tables\Columns\TextColumn::make('product.name')
                    ->label('Producto')
                    ->searchable()
                    ->weight('bold')
                    ->limit(30)
                    ->placeholder('—'),

                Tables\Columns\TextColumn::make('type')
                    ->label('Tipo')
                    ->badge()
                    ->formatStateUsing(function ($state): string {
                        if ($state instanceof InventoryMovementType) {
                            return match ($state) {
                                InventoryMovementType::Purchase => 'Compra',
                                InventoryMovementType::Sale => 'Venta',
                                InventoryMovementType::AdjustmentIn => 'Ajuste +',
                                InventoryMovementType::AdjustmentOut => 'Ajuste -',
                                InventoryMovementType::ReturnIn => 'Dev. Cliente',
                                InventoryMovementType::ReturnOut => 'Dev. Proveedor',
                            };
                        }

                        return match ((string) $state) {
                            'purchase' => 'Compra',
                            'sale' => 'Venta',
                            'adjustment_in' => 'Ajuste +',
                            'adjustment_out' => 'Ajuste -',
                            'return_in' => 'Dev. Cliente',
                            'return_out' => 'Dev. Proveedor',
                            default => (string) $state,
                        };
                    })
                    ->color(function ($state): string {
                        if ($state instanceof InventoryMovementType) {
                            return $state->color();
                        }

                        return InventoryMovementType::tryFrom((string) $state)?->color() ?? 'gray';
                    }),

                Tables\Columns\TextColumn::make('quantity')
                    ->label('Cantidad')
                    ->weight('bold')
                    ->formatStateUsing(function ($state, InventoryMovement $record): string {
                        $qty = abs((float) $state);
                        $sign = $record->isEntry() ? '+' : '−';

                        return $sign.number_format($qty, 3);
                    })
                    ->color(fn (InventoryMovement $record): string => $record->isEntry() ? 'success' : 'danger'),

                Tables\Columns\TextColumn::make('creator.name')
                    ->label('Realizado por')
                    ->default('Sistema')
                    ->placeholder('Sistema')
                    ->toggleable(),
            ])
            ->paginated(false)
            ->emptyStateHeading('No hay movimientos recientes')
            ->emptyStateDescription('Los movimientos aparecerán aquí cuando se realicen ventas o ajustes de stock.')
            ->emptyStateIcon('heroicon-o-arrows-right-left');
    }
}
