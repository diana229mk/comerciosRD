<?php

namespace App\Filament\Widgets;

use App\Filament\Concerns\InteractsWithTenantDashboard;
use App\Filament\Resources\ProductResource;
use App\Models\Product;
use App\Support\DashboardWidgetRegistry;
use Filament\Tables;
use Filament\Tables\Table;
use Filament\Widgets\TableWidget as BaseWidget;

/**
 * Visible por defecto solo en Farmacias.
 */
class ExpiringProductsWidget extends BaseWidget
{
    use InteractsWithTenantDashboard;

    protected static ?string $heading = 'Productos próximos a vencer (30 días)';

    protected static ?int $sort = 13;

    protected int|string|array $columnSpan = [
        'default' => 1,
        'md'      => 1,
        'xl'      => 2,
    ];

    protected static ?string $pollingInterval = '120s';

    protected static function requiredModules(): array
    {
        return ['products'];
    }

    protected static function widgetKey(): ?string
    {
        return DashboardWidgetRegistry::EXPIRING;
    }

    public function table(Table $table): Table
    {
        return $table
            ->query(
                Product::query()
                    ->active()
                    ->expiringSoon(30)
            )
            ->defaultPaginationPageOption(5)
            ->paginated([5])
            ->columns([
                Tables\Columns\TextColumn::make('name')
                    ->label('Producto')
                    ->limit(26)
                    ->weight('medium'),
                Tables\Columns\TextColumn::make('expires_at')
                    ->label('Vence')
                    ->date('d/m/Y')
                    ->color(fn (Product $record) => $record->expires_at?->lte(now()->addDays(7))
                        ? 'danger'
                        : 'warning'),
                Tables\Columns\TextColumn::make('stock')
                    ->label('Stock')
                    ->alignEnd()
                    ->formatStateUsing(fn ($state, Product $record) => number_format((float) $state, 0).' '.$record->unit),
            ])
            ->actions([
                Tables\Actions\Action::make('view')
                    ->icon('heroicon-m-eye')
                    ->url(fn (Product $record) => ProductResource::getUrl('view', ['record' => $record])),
            ])
            ->emptyStateHeading('Sin vencimientos próximos')
            ->emptyStateDescription('Ningún producto vence en los próximos 30 días.')
            ->emptyStateIcon('heroicon-o-shield-check');
    }
}
