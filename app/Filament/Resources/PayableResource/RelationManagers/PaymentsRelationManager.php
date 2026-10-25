<?php

namespace App\Filament\Resources\PayableResource\RelationManagers;

use Filament\Forms;
use Filament\Forms\Form;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Tables;
use Filament\Tables\Table;

class PaymentsRelationManager extends RelationManager
{
    protected static string $relationship = 'payments';

    protected static ?string $title = 'Historial de Pagos';

    protected static ?string $modelLabel = 'Pago';

    protected static ?string $pluralModelLabel = 'Pagos';

    public function form(Form $form): Form
    {
        // Los pagos se crean con el botón "Registrar Pago", no desde aquí
        return $form->schema([]);
    }

    public function table(Table $table): Table
    {
        return $table
            ->recordTitleAttribute('amount')
            ->columns([
                Tables\Columns\TextColumn::make('payment_date')
                    ->label('Fecha')
                    ->date('d/m/Y')
                    ->sortable(),

                Tables\Columns\TextColumn::make('amount')
                    ->label('Monto')
                    ->money('DOP')
                    ->weight('bold')
                    ->color('success'),

                Tables\Columns\TextColumn::make('payment_method')
                    ->label('Método')
                    ->badge()
                    ->formatStateUsing(fn (?string $state): string => match ($state) {
                        'efectivo' => 'Efectivo',
                        'transferencia' => 'Transferencia',
                        'cheque' => 'Cheque',
                        'tarjeta' => 'Tarjeta',
                        default => (string) $state,
                    })
                    ->color(fn (?string $state): string => match ($state) {
                        'efectivo' => 'success',
                        'transferencia' => 'info',
                        'tarjeta' => 'warning',
                        'cheque' => 'gray',
                        default => 'gray',
                    }),

                Tables\Columns\TextColumn::make('reference')
                    ->label('Referencia')
                    ->placeholder('—')
                    ->toggleable(),

                Tables\Columns\TextColumn::make('notes')
                    ->label('Notas')
                    ->limit(40)
                    ->toggleable(isToggledHiddenByDefault: true),

                Tables\Columns\TextColumn::make('creator.name')
                    ->label('Registrado por')
                    ->toggleable(),

                Tables\Columns\TextColumn::make('created_at')
                    ->label('Creado')
                    ->dateTime('d/m/Y H:i')
                    ->toggleable(isToggledHiddenByDefault: true),
            ])
            ->defaultSort('payment_date', 'desc')
            ->headerActions([])
            ->actions([
                Tables\Actions\DeleteAction::make()
                    ->label('Anular')
                    ->modalHeading('Anular este pago')
                    ->modalDescription('Se restará del total pagado y se actualizará el saldo de la cuenta.'),
            ])
            ->bulkActions([])
            ->emptyStateHeading('Sin pagos registrados')
            ->emptyStateDescription('Usa el botón “Registrar Pago” para registrar el primer abono.');
    }
}
