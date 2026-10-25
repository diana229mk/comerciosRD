<?php

namespace App\Filament\Resources;

use App\Filament\Concerns\ChecksBusinessModule;
use App\Filament\Resources\StockCountResource\Pages;
use App\Models\StockCount;
use Filament\Facades\Filament;
use Filament\Resources\Resource;
use Filament\Tables;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;

class StockCountResource extends Resource
{
    use ChecksBusinessModule;

    protected static ?string $model = StockCount::class;

    protected static string $businessModule = 'stock_counts';

    protected static string $viewPermission = 'view_inventory';

    protected static string $createPermission = 'edit_inventory';

    protected static string $editPermission = 'edit_inventory';

    protected static ?string $deletePermission = null;

    protected static ?string $navigationIcon = 'heroicon-o-clipboard-document-check';

    protected static ?string $navigationLabel = 'Conteos Cíclicos';

    protected static ?string $modelLabel = 'Conteo';

    protected static ?string $pluralModelLabel = 'Conteos Cíclicos';

    protected static ?string $navigationGroup = 'Inventario';

    protected static ?int $navigationSort = 30;

    protected static ?string $recordTitleAttribute = 'code';

    protected static ?string $tenantOwnershipRelationshipName = 'business';

    public static function shouldRegisterNavigation(): bool
    {
        $tenant = Filament::getTenant();
        if (! $tenant) return false;
        $eligible = (method_exists($tenant, 'isPharmacy') && $tenant->isPharmacy())
            || (method_exists($tenant, 'usesAdvancedInventory') && $tenant->usesAdvancedInventory());
        if (! $eligible) return false;

        return static::canViewAny();
    }

    public static function canViewAny(): bool
    {
        $tenant = Filament::getTenant();
        if (! $tenant) {
            return false;
        }

        $giroOk = (method_exists($tenant, 'isPharmacy') && $tenant->isPharmacy())
            || (method_exists($tenant, 'usesAdvancedInventory') && $tenant->usesAdvancedInventory());
        if (! $giroOk) {
            return false;
        }

        return auth()->user()?->canPerform('view_inventory') ?? false;
    }

    public static function getEloquentQuery(): Builder
    {
        return parent::getEloquentQuery()->orderByDesc('created_at');
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                Tables\Columns\TextColumn::make('code')->label('Código')->searchable()->sortable(),
                Tables\Columns\TextColumn::make('status')
                    ->label('Estado')
                    ->badge()
                    ->formatStateUsing(fn (?string $state, ?StockCount $record): string => $record?->statusLabel() ?? (string) $state)
                    ->color(fn (?string $state): string => match ($state) {
                        StockCount::STATUS_DRAFT => 'gray',
                        StockCount::STATUS_IN_PROGRESS => 'warning',
                        StockCount::STATUS_COMPLETED => 'success',
                        StockCount::STATUS_CANCELLED => 'danger',
                        default => 'gray',
                    }),
                Tables\Columns\TextColumn::make('scope')
                    ->label('Alcance')
                    ->formatStateUsing(fn (?string $state): string => match ($state) {
                        StockCount::SCOPE_FULL => 'Completo',
                        StockCount::SCOPE_CATEGORY => 'Por categoría',
                        StockCount::SCOPE_SELECTION => 'Selección',
                        default => (string) $state,
                    }),
                Tables\Columns\TextColumn::make('total_items')->label('Ítems')->numeric(),
                Tables\Columns\TextColumn::make('items_with_difference')->label('Con diferencia')->numeric(),
                Tables\Columns\TextColumn::make('total_difference_qty')->label('Δ Qty')->numeric(decimalPlaces: 3),
                Tables\Columns\TextColumn::make('total_cost_impact')
                    ->label('Impacto RD$')
                    ->money('DOP'),
                Tables\Columns\TextColumn::make('completed_at')->label('Completado')->dateTime('d/m/Y H:i'),
            ])
            ->filters([
                Tables\Filters\SelectFilter::make('status')->options([
                    StockCount::STATUS_DRAFT => 'Borrador',
                    StockCount::STATUS_IN_PROGRESS => 'En captura',
                    StockCount::STATUS_COMPLETED => 'Completado',
                    StockCount::STATUS_CANCELLED => 'Cancelado',
                ]),
            ])
            ->actions([
                Tables\Actions\ViewAction::make(),
            ]);
    }

    public static function getPages(): array
    {
        return [
            'index' => Pages\ListStockCounts::route('/'),
            'view' => Pages\ViewStockCount::route('/{record}'),
        ];
    }
}
