<?php

namespace App\Filament\Widgets;

use App\Filament\Resources\PayableResource;
use App\Models\Payable;
use Filament\Facades\Filament;
use Filament\Tables;
use Filament\Tables\Table;
use Filament\Widgets\TableWidget as BaseWidget;

class PayablesDueSoon extends BaseWidget
{
    protected static ?int $sort = 7;

    protected int|string|array $columnSpan = 'full';

    protected static ?string $heading = 'Cuentas por Pagar Próximas a Vencer (Próximos 15 días)';

    protected static ?string $pollingInterval = '60s';

    public static function canView(): bool
    {
        if (! \App\Support\DashboardAccess::allowed()) {
            return false;
        }

        $tenant = filament()->getTenant();

        return $tenant ? $tenant->hasModule('payables') : false;
    }

    public function table(Table $table): Table
    {
        return $table
            ->query(
                Payable::query()
                    ->where('status', '!=', 'pagada')
                    ->where('balance', '>', 0)
                    ->whereBetween('due_date', [
                        now()->startOfDay()->toDateString(),
                        now()->addDays(15)->endOfDay()->toDateString(),
                    ])
                    ->orderBy('due_date')
                    ->limit(8)
            )
            ->columns([
                Tables\Columns\TextColumn::make('supplier_name')
                    ->label('Proveedor')
                    ->searchable()
                    ->weight('bold'),

                Tables\Columns\TextColumn::make('reference')
                    ->label('Factura')
                    ->toggleable()
                    ->placeholder('—'),

                Tables\Columns\TextColumn::make('amount')
                    ->label('Monto')
                    ->money('DOP'),

                Tables\Columns\TextColumn::make('paid_amount')
                    ->label('Pagado')
                    ->money('DOP')
                    ->color('success'),

                Tables\Columns\TextColumn::make('balance')
                    ->label('Saldo Pendiente')
                    ->money('DOP')
                    ->color('danger')
                    ->weight('bold'),

                Tables\Columns\TextColumn::make('due_date')
                    ->label('Vence')
                    ->date('d/m/Y')
                    ->color(fn ($state) => $state && $state < now()->addDays(7)->startOfDay()
                        ? 'danger'
                        : 'warning'),
            ])
            ->actions([
                Tables\Actions\Action::make('ver')
                    ->label('Ver')
                    ->icon('heroicon-o-eye')
                    ->url(fn (Payable $record): string => PayableResource::getUrl('edit', [
                        'tenant' => Filament::getTenant(),
                        'record' => $record,
                    ])),
            ])
            ->paginated(false)
            ->emptyStateHeading('No hay cuentas por pagar próximas a vencer')
            ->emptyStateDescription('¡Buen trabajo! No tienes deudas urgentes.')
            ->emptyStateIcon('heroicon-o-check-circle');
    }
}
