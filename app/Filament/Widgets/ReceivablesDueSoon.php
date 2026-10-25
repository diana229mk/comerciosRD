<?php

namespace App\Filament\Widgets;

use App\Filament\Resources\ReceivableResource;
use App\Models\Receivable;
use Filament\Facades\Filament;
use Filament\Tables;
use Filament\Tables\Table;
use Filament\Widgets\TableWidget as BaseWidget;

class ReceivablesDueSoon extends BaseWidget
{
    protected static ?int $sort = 8;

    protected int|string|array $columnSpan = 'full';

    protected static ?string $heading = 'Cuentas por Cobrar Próximas a Vencer (Próximos 15 días)';

    protected static ?string $pollingInterval = '60s';

    public static function canView(): bool
    {
        if (! \App\Support\DashboardAccess::allowed()) {
            return false;
        }

        $tenant = filament()->getTenant();

        return $tenant ? $tenant->hasModule('receivables') : false;
    }

    public function table(Table $table): Table
    {
        return $table
            ->query(
                Receivable::query()
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
                Tables\Columns\TextColumn::make('customer_name')
                    ->label('Cliente')
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
                    ->url(fn (Receivable $record): string => ReceivableResource::getUrl('edit', [
                        'tenant' => Filament::getTenant(),
                        'record' => $record,
                    ])),
            ])
            ->paginated(false)
            ->emptyStateHeading('No hay cuentas por cobrar próximas a vencer')
            ->emptyStateDescription('¡Excelente! Tus cobros están al día.')
            ->emptyStateIcon('heroicon-o-check-circle');
    }
}
