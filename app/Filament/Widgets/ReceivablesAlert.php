<?php

namespace App\Filament\Widgets;

use App\Models\Receivable;
use Filament\Facades\Filament;
use Filament\Tables;
use Filament\Tables\Table;
use Filament\Widgets\TableWidget as BaseWidget;

class ReceivablesAlert extends BaseWidget
{
    protected static ?int $sort = 5;

    protected int|string|array $columnSpan = 'full';

    protected static ?string $heading = 'Cuentas por cobrar · próximos 7 días';

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
                    ->dueSoon(7)
                    ->limit(8)
            )
            ->columns([
                Tables\Columns\TextColumn::make('customer_name')
                    ->label('Cliente')
                    ->weight('medium'),
                Tables\Columns\TextColumn::make('due_date')
                    ->label('Vence')
                    ->date('d/m/Y')
                    ->color(fn (Receivable $record) => $record->due_date?->isPast() ? 'danger' : 'warning'),
                Tables\Columns\TextColumn::make('balance')
                    ->label('Saldo')
                    ->money('DOP')
                    ->color('danger'),
                Tables\Columns\TextColumn::make('status')
                    ->label('Estado')
                    ->badge()
                    ->formatStateUsing(fn (string $state): string => match ($state) {
                        'pendiente' => 'Pendiente',
                        'parcial' => 'Parcial',
                        'vencida' => 'Vencida',
                        default => $state,
                    })
                    ->color(fn (string $state): string => match ($state) {
                        'pendiente' => 'warning',
                        'parcial' => 'info',
                        'vencida' => 'danger',
                        default => 'gray',
                    }),
            ])
            ->actions([
                Tables\Actions\Action::make('ver')
                    ->icon('heroicon-o-eye')
                    ->url(fn (Receivable $record) => route('filament.admin.resources.receivables.edit', [
                        'tenant' => Filament::getTenant(),
                        'record' => $record,
                    ])),
            ])
            ->paginated(false)
            ->emptyStateHeading('Sin cobros próximos')
            ->emptyStateDescription('No hay cuentas por cobrar en los próximos 7 días.')
            ->emptyStateIcon('heroicon-o-check-circle');
    }
}
