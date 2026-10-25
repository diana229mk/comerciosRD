<?php

namespace App\Filament\Widgets;

use App\Enums\OrderStatus;
use App\Enums\OrderType;
use App\Models\Order;
use Filament\Facades\Filament;
use Filament\Tables;
use Filament\Tables\Table;
use Filament\Widgets\TableWidget as BaseWidget;

class OrdersPending extends BaseWidget
{
    protected static ?int $sort = 2;

    protected int|string|array $columnSpan = 'full';

    protected static ?string $heading = 'Órdenes Pendientes';

    protected static ?string $pollingInterval = '30s';

    public static function canView(): bool
    {
        if (! \App\Support\DashboardAccess::allowed()) {
            return false;
        }

        $tenant = filament()->getTenant();

        return $tenant ? $tenant->hasModule('orders') : false;
    }

    public function table(Table $table): Table
    {
        return $table
            ->query(
                Order::query()
                    ->whereIn('status', ['pendiente', 'en_preparacion', 'lista'])
                    ->latest()
                    ->limit(8)
            )
            ->columns([
                Tables\Columns\TextColumn::make('order_number')
                    ->label('Número')
                    ->weight('bold')
                    ->searchable(),

                Tables\Columns\TextColumn::make('order_type')
                    ->label('Tipo')
                    ->badge()
                    ->formatStateUsing(function ($state): string {
                        $value = $state instanceof OrderType ? $state->value : (string) $state;

                        return $value === 'por_nombre' ? 'Por Nombre' : 'Por Número';
                    }),

                Tables\Columns\TextColumn::make('customer_name')
                    ->label('Cliente')
                    ->placeholder('—'),

                Tables\Columns\TextColumn::make('status')
                    ->label('Estado')
                    ->badge()
                    ->formatStateUsing(function ($state): string {
                        if ($state instanceof OrderStatus) {
                            return $state->label();
                        }

                        return match ((string) $state) {
                            'pendiente' => 'Pendiente',
                            'en_preparacion' => 'En Preparación',
                            'lista' => 'Lista',
                            default => (string) $state,
                        };
                    })
                    ->color(function ($state): string {
                        $value = $state instanceof OrderStatus ? $state->value : (string) $state;

                        return match ($value) {
                            'pendiente' => 'warning',
                            'en_preparacion' => 'info',
                            'lista' => 'success',
                            default => 'gray',
                        };
                    }),

                Tables\Columns\TextColumn::make('total')
                    ->label('Total')
                    ->money('DOP'),

                Tables\Columns\TextColumn::make('created_at')
                    ->label('Hace')
                    ->since(),
            ])
            ->actions([
                Tables\Actions\Action::make('ver')
                    ->label('Ver')
                    ->icon('heroicon-o-eye')
                    ->url(function (Order $record): string {
                        $tenant = Filament::getTenant();

                        return route('filament.admin.resources.orders.edit', [
                            'tenant' => $tenant,
                            'record' => $record,
                        ]);
                    }),
            ])
            ->paginated(false)
            ->emptyStateHeading('Sin órdenes pendientes')
            ->emptyStateDescription('Cuando lleguen pedidos, aparecerán aquí.');
    }
}
