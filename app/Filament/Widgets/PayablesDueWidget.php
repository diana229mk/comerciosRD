<?php

namespace App\Filament\Widgets;

use App\Filament\Concerns\InteractsWithTenantDashboard;
use App\Models\AccountPayable;
use App\Support\DashboardWidgetRegistry;
use App\Support\Money;
use Filament\Tables;
use Filament\Tables\Table;
use Filament\Widgets\TableWidget as BaseWidget;

class PayablesDueWidget extends BaseWidget
{
    use InteractsWithTenantDashboard;

    protected static ?string $heading = 'Cuentas por pagar · próximos 7 días';

    protected static ?int $sort = 11;

    protected int|string|array $columnSpan = [
        'default' => 1,
        'md'      => 1,
        'xl'      => 2,
    ];

    protected static ?string $pollingInterval = '60s';

    protected static function requiredModules(): array
    {
        return ['payables'];
    }

    protected static function widgetKey(): ?string
    {
        return DashboardWidgetRegistry::PAYABLES_DUE;
    }

    public function table(Table $table): Table
    {
        return $table
            ->query(
                AccountPayable::query()
                    ->dueWithinDays(7)
                    ->orderBy('due_date')
            )
            ->defaultPaginationPageOption(5)
            ->paginated([5])
            ->columns([
                Tables\Columns\TextColumn::make('supplier_name')
                    ->label('Proveedor')
                    ->limit(24)
                    ->weight('medium'),
                Tables\Columns\TextColumn::make('due_date')
                    ->label('Vence')
                    ->date('d/m/Y')
                    ->sortable(false),
                Tables\Columns\TextColumn::make('amount')
                    ->label('Saldo')
                    ->alignEnd()
                    ->state(fn (AccountPayable $record) => Money::format($record->balance()))
                    ->color('warning'),
            ])
            ->emptyStateHeading('Sin pagos próximos')
            ->emptyStateDescription('No hay cuentas por pagar en los próximos 7 días.')
            ->emptyStateIcon('heroicon-o-calendar-days');
    }
}
