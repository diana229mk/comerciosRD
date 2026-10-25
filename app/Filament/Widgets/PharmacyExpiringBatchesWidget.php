<?php

namespace App\Filament\Widgets;

use App\Filament\Concerns\InteractsWithTenantDashboard;
use App\Models\ProductBatch;
use App\Support\DashboardWidgetRegistry;
use Filament\Facades\Filament;
use Filament\Tables;
use Filament\Tables\Table;
use Filament\Widgets\TableWidget as BaseWidget;
use Illuminate\Database\Eloquent\Builder;

/**
 * Tabla de lotes próximos a vencer / vencidos (SOLO Farmacia).
 * Con tabs 30/60/90 días + tab de vencidos.
 */
class PharmacyExpiringBatchesWidget extends BaseWidget
{
    use InteractsWithTenantDashboard;

    protected static ?string $heading = 'Lotes por vencer';

    protected static ?int $sort = 12;

    protected int|string|array $columnSpan = 'full';

    protected static ?string $pollingInterval = '180s';

    public string $activeTab = 'd30';

    protected static function requiredModules(): array
    {
        return ['products'];
    }

    protected static function widgetKey(): ?string
    {
        return DashboardWidgetRegistry::PHARMACY_EXPIRY_ALERTS;
    }

    public static function canView(): bool
    {
        if (! \App\Support\DashboardAccess::allowed()) {
            return false;
        }

        $tenant = Filament::getTenant();
        if (! $tenant || ! method_exists($tenant, 'isPharmacy') || ! $tenant->isPharmacy()) {
            return false;
        }

        return parent::canView();
    }

    public function getTabs(): array
    {
        return [
            'expired' => Tables\Filters\Tab::make('Vencidos')
                ->modifyQueryUsing(fn (Builder $q) => $q->expired())
                ->icon('heroicon-m-x-circle'),

            'd30' => Tables\Filters\Tab::make('≤ 30 días')
                ->modifyQueryUsing(fn (Builder $q) => $q->expiringSoon(30))
                ->icon('heroicon-m-exclamation-triangle'),

            'd60' => Tables\Filters\Tab::make('31–60 días')
                ->modifyQueryUsing(fn (Builder $q) => $q
                    ->whereNotNull('expires_at')
                    ->whereDate('expires_at', '>', now()->addDays(30)->toDateString())
                    ->whereDate('expires_at', '<=', now()->addDays(60)->toDateString()))
                ->icon('heroicon-m-clock'),

            'd90' => Tables\Filters\Tab::make('61–90 días')
                ->modifyQueryUsing(fn (Builder $q) => $q
                    ->whereNotNull('expires_at')
                    ->whereDate('expires_at', '>', now()->addDays(60)->toDateString())
                    ->whereDate('expires_at', '<=', now()->addDays(90)->toDateString()))
                ->icon('heroicon-m-calendar'),
        ];
    }

    public function table(Table $table): Table
    {
        return $table
            ->query(
                ProductBatch::query()
                    ->available()
                    ->whereNotNull('expires_at')
                    ->with('product')
                    ->fefoOrder()
            )
            ->defaultPaginationPageOption(10)
            ->paginated([10, 25, 50])
            ->columns([
                Tables\Columns\TextColumn::make('product.name')
                    ->label('Medicamento')
                    ->searchable()
                    ->limit(40)
                    ->weight('medium'),

                Tables\Columns\TextColumn::make('code')
                    ->label('Lote')
                    ->badge()
                    ->color('gray')
                    ->placeholder('—'),

                Tables\Columns\TextColumn::make('expires_at')
                    ->label('Vence')
                    ->date('d/m/Y')
                    ->sortable()
                    ->color(fn (ProductBatch $r) => match (true) {
                        $r->isExpired() => 'danger',
                        $r->isNearExpiry(30) => 'danger',
                        $r->isNearExpiry(60) => 'warning',
                        default => 'warning',
                    }),

                Tables\Columns\TextColumn::make('days_left')
                    ->label('Días')
                    ->badge()
                    ->state(fn (ProductBatch $r) => $r->isExpired()
                        ? 'vencido'
                        : (($r->daysToExpire() ?? 0).'d'))
                    ->color(fn (ProductBatch $r): string => match (true) {
                        $r->isExpired() => 'danger',
                        $r->isNearExpiry(30) => 'danger',
                        $r->isNearExpiry(60) => 'warning',
                        default => 'warning',
                    }),

                Tables\Columns\TextColumn::make('quantity')
                    ->label('Existencia')
                    ->numeric(decimalPlaces: 3)
                    ->alignEnd(),
            ])
            ->actions([
                Tables\Actions\Action::make('view_product')
                    ->label('Producto')
                    ->icon('heroicon-m-eye')
                    ->url(fn (ProductBatch $r) => $r->product
                        ? \App\Filament\Resources\ProductResource::getUrl('view', ['record' => $r->product])
                        : null)
                    ->visible(fn (ProductBatch $r) => (bool) $r->product),
            ])
            ->emptyStateHeading('Sin lotes en este rango')
            ->emptyStateDescription('Cambia de pestaña para revisar otros tramos.')
            ->emptyStateIcon('heroicon-o-shield-check');
    }
}
