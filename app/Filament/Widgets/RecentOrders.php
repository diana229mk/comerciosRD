<?php

namespace App\Filament\Widgets;

use App\Enums\OrderType;
use App\Models\Order;
use Filament\Facades\Filament;
use Filament\Tables;
use Filament\Tables\Table;
use Filament\Widgets\TableWidget as BaseWidget;

class RecentOrders extends BaseWidget
{
    protected static ?int $sort = 5;

    protected int|string|array $columnSpan = 'full';

    protected static ?string $heading = 'Últimas Órdenes Completadas';

    protected static ?string $pollingInterval = '30s';

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
                Order::query()
                    ->where('status', 'completada')
                    ->latest('completed_at')
                    ->limit(6)
            )
            ->columns([
                Tables\Columns\TextColumn::make('order_number')
                    ->label('Número')
                    ->weight('bold'),

                Tables\Columns\TextColumn::make('order_type')
                    ->label('Tipo')
                    ->badge()
                    ->formatStateUsing(function ($state): string {
                        $value = $state instanceof OrderType ? $state->value : (string) $state;

                        return $value === 'por_nombre' ? 'Por Nombre' : 'Por Número';
                    })
                    ->color(function ($state): string {
                        $value = $state instanceof OrderType ? $state->value : (string) $state;

                        return $value === 'por_nombre' ? 'info' : 'success';
                    }),

                Tables\Columns\TextColumn::make('customer_name')
                    ->label('Cliente')
                    ->placeholder('—'),

                Tables\Columns\TextColumn::make('total')
                    ->label('Total')
                    ->money('DOP'),

                Tables\Columns\TextColumn::make('completed_at')
                    ->label('Completada')
                    ->since(),
            ])
            ->actions([
                Tables\Actions\Action::make('ver')
                    ->label('Ver')
                    ->icon('heroicon-o-eye')
                    ->url(function (Order $record): string {
                        return route('filament.admin.resources.orders.edit', [
                            'tenant' => Filament::getTenant(),
                            'record' => $record,
                        ]);
                    }),
            ])
            ->paginated(false)
            ->emptyStateHeading('Sin órdenes completadas')
            ->emptyStateDescription('Las órdenes marcadas como completadas aparecerán aquí.')
            ->emptyStateIcon('heroicon-o-clipboard-document-check');
    }
}
