<?php

namespace App\Filament\Resources;

use App\Enums\InventoryMovementType;
use App\Filament\Concerns\ChecksBusinessModule;
use App\Filament\Resources\InventoryMovementResource\Pages;
use App\Models\InventoryMovement;
use Filament\Forms;
use Filament\Forms\Form;
use Filament\Infolists;
use Filament\Infolists\Infolist;
use Filament\Resources\Resource;
use Filament\Tables;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;

class InventoryMovementResource extends Resource
{
    use ChecksBusinessModule;

    protected static string $businessModule = 'inventory_movements';

    protected static string $viewPermission = 'view_inventory';
    protected static string $createPermission = 'adjust_inventory';
    protected static ?string $editPermission = null;
    protected static ?string $deletePermission = null;

    protected static ?string $model = InventoryMovement::class;

    protected static ?string $navigationIcon = 'heroicon-o-arrows-right-left';

    protected static ?string $navigationLabel = 'Movimientos de Inventario';

    protected static ?string $modelLabel = 'Movimiento de Inventario';

    protected static ?string $pluralModelLabel = 'Movimientos de Inventario';

    protected static ?string $navigationGroup = 'Inventario';

    protected static ?int $navigationSort = 3;

    protected static ?string $tenantOwnershipRelationshipName = 'business';

    public static function form(Form $form): Form
    {
        return $form
            ->schema([
                Forms\Components\Section::make('Información del Movimiento')
                    ->schema([
                        Forms\Components\Select::make('product_id')
                            ->label('Producto')
                            ->relationship(
                                name: 'product',
                                titleAttribute: 'name',
                                modifyQueryUsing: fn (Builder $query): Builder => $query
                                    ->where('is_active', true)
                                    ->orderBy('name'),
                            )
                            ->searchable()
                            ->preload()
                            ->required()
                            ->native(false),

                        Forms\Components\Select::make('type')
                            ->label('Tipo de Movimiento')
                            ->options(InventoryMovementType::options())
                            ->required()
                            ->native(false)
                            ->helperText('Ventas se generan solas. Aquí usa ajustes, compras o devoluciones.'),

                        Forms\Components\TextInput::make('quantity')
                            ->label('Cantidad')
                            ->numeric()
                            ->required()
                            ->minValue(0.001)
                            ->step(0.001)
                            ->helperText('Siempre positiva. El tipo define si entra o sale stock.'),

                        Forms\Components\TextInput::make('unit_cost')
                            ->label('Costo Unitario')
                            ->numeric()
                            ->prefix('RD$')
                            ->nullable(),

                        Forms\Components\Textarea::make('reason')
                            ->label('Motivo / Observación')
                            ->rows(3)
                            ->required()
                            ->columnSpanFull(),
                    ])
                    ->columns(2),
            ]);
    }

    public static function infolist(Infolist $infolist): Infolist
    {
        return $infolist
            ->schema([
                Infolists\Components\Section::make('Movimiento')
                    ->schema([
                        Infolists\Components\TextEntry::make('created_at')
                            ->label('Fecha')
                            ->dateTime('d/m/Y H:i'),
                        Infolists\Components\TextEntry::make('product.name')
                            ->label('Producto'),
                        Infolists\Components\TextEntry::make('type')
                            ->label('Tipo')
                            ->badge()
                            ->formatStateUsing(fn ($state): string => $state instanceof InventoryMovementType
                                ? $state->label()
                                : (InventoryMovementType::tryFrom((string) $state)?->label() ?? (string) $state))
                            ->color(fn ($state): string => $state instanceof InventoryMovementType
                                ? $state->color()
                                : (InventoryMovementType::tryFrom((string) $state)?->color() ?? 'gray')),
                        Infolists\Components\TextEntry::make('quantity')
                            ->label('Cantidad')
                            ->formatStateUsing(function ($state, InventoryMovement $record): string {
                                $qty = abs((float) $state);
                                $sign = $record->isEntry() ? '+' : '−';

                                return $sign.number_format($qty, 3);
                            }),
                        Infolists\Components\TextEntry::make('stock_after')
                            ->label('Stock después')
                            ->placeholder('—'),
                        Infolists\Components\TextEntry::make('unit_cost')
                            ->label('Costo unitario')
                            ->money('DOP')
                            ->placeholder('—'),
                        Infolists\Components\TextEntry::make('creator.name')
                            ->label('Registrado por')
                            ->placeholder('—'),
                        Infolists\Components\TextEntry::make('reason')
                            ->label('Motivo')
                            ->columnSpanFull()
                            ->placeholder('—'),
                        Infolists\Components\TextEntry::make('reference_type')
                            ->label('Referencia')
                            ->formatStateUsing(function ($state, InventoryMovement $record): string {
                                if (! $state) {
                                    return '—';
                                }

                                return class_basename((string) $state).' #'.($record->reference_id ?? '');
                            })
                            ->columnSpanFull(),
                    ])
                    ->columns(2),
            ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                Tables\Columns\TextColumn::make('created_at')
                    ->label('Fecha')
                    ->dateTime('d/m/Y H:i')
                    ->sortable(),

                Tables\Columns\TextColumn::make('product.name')
                    ->label('Producto')
                    ->searchable()
                    ->sortable()
                    ->weight('bold')
                    ->placeholder('—'),

                Tables\Columns\TextColumn::make('type')
                    ->label('Tipo')
                    ->badge()
                    ->formatStateUsing(fn ($state): string => $state instanceof InventoryMovementType
                        ? $state->label()
                        : (InventoryMovementType::tryFrom((string) $state)?->label() ?? (string) $state))
                    ->color(fn ($state): string => $state instanceof InventoryMovementType
                        ? $state->color()
                        : (InventoryMovementType::tryFrom((string) $state)?->color() ?? 'gray')),

                Tables\Columns\TextColumn::make('quantity')
                    ->label('Cantidad')
                    ->sortable()
                    ->formatStateUsing(function ($state, InventoryMovement $record): string {
                        $qty = abs((float) $state);
                        $sign = $record->isEntry() ? '+' : '−';

                        return $sign.number_format($qty, 3);
                    })
                    ->color(fn (InventoryMovement $record): string => $record->isEntry() ? 'success' : 'danger'),

                Tables\Columns\TextColumn::make('stock_after')
                    ->label('Stock después')
                    ->numeric(decimalPlaces: 3)
                    ->toggleable(),

                Tables\Columns\TextColumn::make('unit_cost')
                    ->label('Costo Unit.')
                    ->money('DOP')
                    ->sortable()
                    ->toggleable(isToggledHiddenByDefault: true),

                Tables\Columns\TextColumn::make('reason')
                    ->label('Motivo')
                    ->limit(40)
                    ->tooltip(fn (InventoryMovement $record): ?string => $record->reason)
                    ->toggleable(),

                Tables\Columns\TextColumn::make('creator.name')
                    ->label('Registrado por')
                    ->toggleable(isToggledHiddenByDefault: true)
                    ->placeholder('—'),
            ])
            ->defaultSort('created_at', 'desc')
            ->filters([
                Tables\Filters\SelectFilter::make('type')
                    ->label('Tipo de Movimiento')
                    ->options(InventoryMovementType::options()),

                Tables\Filters\SelectFilter::make('product_id')
                    ->label('Producto')
                    ->relationship('product', 'name')
                    ->searchable()
                    ->preload(),

                Tables\Filters\Filter::make('created_at')
                    ->form([
                        Forms\Components\DatePicker::make('desde')
                            ->label('Desde')
                            ->native(false),
                        Forms\Components\DatePicker::make('hasta')
                            ->label('Hasta')
                            ->native(false),
                    ])
                    ->query(function (Builder $query, array $data): Builder {
                        return $query
                            ->when(
                                $data['desde'] ?? null,
                                fn (Builder $query, $date): Builder => $query->whereDate('created_at', '>=', $date),
                            )
                            ->when(
                                $data['hasta'] ?? null,
                                fn (Builder $query, $date): Builder => $query->whereDate('created_at', '<=', $date),
                            );
                    }),
            ])
            ->actions([
                Tables\Actions\ViewAction::make(),
            ])
            ->bulkActions([])
            ->emptyStateHeading('No hay movimientos de inventario')
            ->emptyStateDescription('Los movimientos se crean al vender o al ajustar stock en Productos.')
            ->emptyStateIcon('heroicon-o-arrows-right-left');
    }

    public static function getRelations(): array
    {
        return [];
    }

    public static function getPages(): array
    {
        return [
            'index' => Pages\ListInventoryMovements::route('/'),
            'create' => Pages\CreateInventoryMovement::route('/create'),
            'view' => Pages\ViewInventoryMovement::route('/{record}'),
        ];
    }

    public static function getEloquentQuery(): Builder
    {
        return parent::getEloquentQuery()
            ->with(['product', 'creator']);
    }

    public static function canViewAny(): bool
    {
        if (! static::tenantHasModule()) {
            return false;
        }

        // Tabla: supervisor, admin, owner
        return auth()->user()?->canPerform('view_inventory') ?? false;
    }

    public static function canCreate(): bool
    {
        if (! static::tenantHasModule()) {
            return false;
        }

        // Ajustes: supervisor, admin, owner
        return auth()->user()?->hasRoleInBusiness(['owner', 'admin', 'supervisor']) ?? false;
    }

    public static function canEdit($record): bool
    {
        return false; // Los movimientos no se editan
    }

    public static function canDelete($record): bool
    {
        return false; // Los movimientos no se eliminan
    }

    public static function canDeleteAny(): bool
    {
        return false;
    }
}
