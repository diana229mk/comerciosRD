<?php

namespace App\Filament\Widgets;

use App\Filament\Concerns\InteractsWithTenantDashboard;
use App\Models\AccountReceivable;
use App\Support\DashboardWidgetRegistry;
use App\Support\Money;
use Filament\Tables;
use Filament\Tables\Table;
use Filament\Widgets\TableWidget as BaseWidget;

/**
 * Visible por defecto solo en Distribuidoras (y almacenes).
 */
class ReceivablesPendingWidget extends BaseWidget
{
    use InteractsWithTenantDashboard;

    protected static ?string $heading = 'Cuentas por cobrar pendientes';

    protected static ?int $sort = 12;

    protected int|string|array $columnSpan = [
        'default' => 1,
        'md'      => 1,
        'xl'      => 2,
    ];

    protected static ?string $pollingInterval = '60s';

    protected static function requiredModules(): array
    {
        return ['receivables'];
    }

    protected static function widgetKey(): ?string
    {
        return DashboardWidgetRegistry::RECEIVABLES;
    }

    public function table(Table $table): Table
    {
        return $table
            ->query(
                AccountReceivable::query()
                    ->open()
                    ->orderByRaw('(amount - amount_paid) desc')
            )
            ->defaultPaginationPageOption(5)
            ->paginated([5])
            ->columns([
                Tables\Columns\TextColumn::make('customer_name')
                    ->label('Cliente')
                    ->limit(24)
                    ->weight('medium'),
                Tables\Columns\TextColumn::make('due_date')
                    ->label('Vence')
                    ->date('d/m/Y')
                    ->placeholder('—'),
                Tables\Columns\TextColumn::make('balance')
                    ->label('Saldo')
                    ->alignEnd()
                    ->state(fn (AccountReceivable $record) => Money::format($record->balance()))
                    ->color('danger'),
                Tables\Columns\TextColumn::make('status')
                    ->label('Estado')
                    ->badge()
                    ->formatStateUsing(fn ($state) => $state?->label() ?? (string) $state)
                    ->color(fn ($state) => $state?->color() ?? 'gray'),
            ])
            ->emptyStateHeading('Sin cuentas por cobrar')
            ->emptyStateDescription('No hay saldos pendientes de clientes.')
            ->emptyStateIcon('heroicon-o-banknotes');
    }
}
