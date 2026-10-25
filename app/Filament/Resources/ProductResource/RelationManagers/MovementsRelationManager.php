<?php

namespace App\Filament\Resources\ProductResource\RelationManagers;

use App\Enums\InventoryMovementType;
use App\Models\InventoryMovement;
use Filament\Forms\Form;
use Filament\Infolists;
use Filament\Infolists\Infolist;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Tables;
use Filament\Tables\Table;

class MovementsRelationManager extends RelationManager
{
    use Concerns\LoadsEagerly;

    protected static string $relationship = 'movements';

    protected static ?string $title = 'Historial de Movimientos de Inventario';

    protected static ?string $modelLabel = 'Movimiento';

    protected static ?string $pluralModelLabel = 'Movimientos';

    protected static ?string $recordTitleAttribute = 'id';

    public function form(Form $form): Form
    {
        // Solo lectura: los movimientos se crean al vender o con "Ajustar Stock"
        return $form->schema([]);
    }

    public function infolist(Infolist $infolist): Infolist
    {
        return $infolist
            ->schema([
                Infolists\Components\TextEntry::make('created_at')
                    ->label('Fecha')
                    ->dateTime('d/m/Y H:i'),
                Infolists\Components\TextEntry::make('type')
                    ->label('Tipo')
                    ->badge()
                    ->formatStateUsing(fn ($state): string => self::typeLabel($state))
                    ->color(fn ($state): string => self::typeColor($state)),
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
                Infolists\Components\TextEntry::make('creator.name')
                    ->label('Realizado por')
                    ->default('Sistema')
                    ->placeholder('Sistema'),
                Infolists\Components\TextEntry::make('reason')
                    ->label('Motivo')
                    ->columnSpanFull()
                    ->placeholder('—'),
            ])
            ->columns(2);
    }

    public function table(Table $table): Table
    {
        return $table
            ->recordTitleAttribute('id')
            ->modifyQueryUsing(fn ($query) => $query->with('creator'))
            ->columns([
                Tables\Columns\TextColumn::make('created_at')
                    ->label('Fecha')
                    ->dateTime('d/m/Y H:i')
                    ->sortable()
                    ->searchable(),

                Tables\Columns\TextColumn::make('type')
                    ->label('Tipo')
                    ->badge()
                    ->formatStateUsing(fn ($state): string => self::typeLabel($state))
                    ->color(fn ($state): string => self::typeColor($state)),

                Tables\Columns\TextColumn::make('quantity')
                    ->label('Cantidad')
                    ->sortable()
                    ->weight('bold')
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

                Tables\Columns\TextColumn::make('reason')
                    ->label('Motivo')
                    ->limit(40)
                    ->tooltip(fn (InventoryMovement $record): ?string => $record->reason)
                    ->wrap()
                    ->placeholder('—'),

                Tables\Columns\TextColumn::make('creator.name')
                    ->label('Realizado por')
                    ->sortable()
                    ->searchable()
                    ->default('Sistema')
                    ->placeholder('Sistema')
                    ->badge()
                    ->color('gray'),
            ])
            ->defaultSort('created_at', 'desc')
            ->filters([
                Tables\Filters\SelectFilter::make('type')
                    ->label('Tipo de Movimiento')
                    ->options(InventoryMovementType::options()),
            ])
            ->headerActions([
                // Sin crear aquí: se usa "Ajustar Stock" o ventas automáticas
            ])
            ->actions([
                Tables\Actions\ViewAction::make()
                    ->modalHeading('Detalle del movimiento'),
            ])
            ->bulkActions([
                // Sin eliminar: historial inmutable
            ])
            ->emptyStateHeading('No hay movimientos registrados')
            ->emptyStateDescription('Los movimientos se generan al vender o al ajustar stock.')
            ->emptyStateIcon('heroicon-o-arrows-right-left');
    }

    protected static function typeLabel(mixed $state): string
    {
        if ($state instanceof InventoryMovementType) {
            return $state->label();
        }

        return InventoryMovementType::tryFrom((string) $state)?->label() ?? (string) $state;
    }

    protected static function typeColor(mixed $state): string
    {
        if ($state instanceof InventoryMovementType) {
            return $state->color();
        }

        return InventoryMovementType::tryFrom((string) $state)?->color() ?? 'gray';
    }
}
