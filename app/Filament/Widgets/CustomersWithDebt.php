<?php

namespace App\Filament\Widgets;

use App\Filament\Resources\CustomerResource;
use App\Filament\Resources\ReceivableResource;
use App\Models\Customer;
use Filament\Facades\Filament;
use Filament\Tables;
use Filament\Tables\Table;
use Filament\Widgets\TableWidget as BaseWidget;
use Illuminate\Database\Eloquent\Builder;

class CustomersWithDebt extends BaseWidget
{
    protected static ?int $sort = 6;

    protected int|string|array $columnSpan = 'full';

    protected static ?string $heading = 'Clientes con Deuda Pendiente';

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
                Customer::query()
                    ->where('is_active', true)
                    ->whereHas('receivables', function (Builder $query): void {
                        $query->where('balance', '>', 0)
                            ->whereIn('status', ['pendiente', 'parcial', 'vencida']);
                    })
                    ->withSum([
                        'receivables as receivables_sum_balance' => function (Builder $query): void {
                            $query->where('balance', '>', 0)
                                ->whereIn('status', ['pendiente', 'parcial', 'vencida']);
                        },
                    ], 'balance')
                    ->withCount([
                        'receivables as open_receivables_count' => function (Builder $query): void {
                            $query->where('balance', '>', 0)
                                ->whereIn('status', ['pendiente', 'parcial', 'vencida']);
                        },
                    ])
                    ->orderByDesc('receivables_sum_balance')
                    ->limit(10)
            )
            ->columns([
                Tables\Columns\TextColumn::make('name')
                    ->label('Cliente')
                    ->searchable()
                    ->weight('bold'),

                Tables\Columns\TextColumn::make('phone')
                    ->label('Teléfono')
                    ->toggleable()
                    ->placeholder('—'),

                Tables\Columns\TextColumn::make('receivables_sum_balance')
                    ->label('Total Adeudado')
                    ->money('DOP')
                    ->color('danger')
                    ->weight('bold'),

                Tables\Columns\TextColumn::make('open_receivables_count')
                    ->label('Cuentas Pendientes')
                    ->badge()
                    ->color('warning'),
            ])
            ->actions([
                Tables\Actions\Action::make('ver_cuentas')
                    ->label('Ver Cuentas')
                    ->icon('heroicon-o-eye')
                    ->url(function (Customer $record): string {
                        $tenant = Filament::getTenant();

                        return ReceivableResource::getUrl('index', [
                            'tenant' => $tenant,
                            'tableFilters' => [
                                'customer_id' => [
                                    'value' => $record->getKey(),
                                ],
                            ],
                        ]);
                    }),

                Tables\Actions\Action::make('ver_cliente')
                    ->label('Cliente')
                    ->icon('heroicon-o-user')
                    ->color('gray')
                    ->url(fn (Customer $record): string => CustomerResource::getUrl('edit', [
                        'tenant' => Filament::getTenant(),
                        'record' => $record,
                    ])),
            ])
            ->paginated(false)
            ->emptyStateHeading('No hay clientes con deuda')
            ->emptyStateDescription('¡Excelente! Todos tus clientes están al día.')
            ->emptyStateIcon('heroicon-o-check-circle');
    }
}
