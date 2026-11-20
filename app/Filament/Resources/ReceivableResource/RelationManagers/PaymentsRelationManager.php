<?php

namespace App\Filament\Resources\ReceivableResource\RelationManagers;

use App\Services\ReceivablePaymentService;
use Filament\Forms;
use Filament\Forms\Form;
use Filament\Notifications\Notification;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Tables;
use Filament\Tables\Table;
use Illuminate\Support\Str;

class PaymentsRelationManager extends RelationManager
{
    protected static string $relationship = 'payments';

    protected static ?string $title = 'Historial de Cobros';

    protected static ?string $modelLabel = 'Cobro';

    protected static ?string $pluralModelLabel = 'Cobros';

    public function form(Form $form): Form
    {
        // Los cobros se crean con el botón "Registrar Cobro"
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
                    ->color(fn ($record): string => $record->reversal ? 'danger' : 'success'),

                Tables\Columns\TextColumn::make('reversal.created_at')
                    ->label('Estado')
                    ->dateTime('d/m/Y H:i')
                    ->placeholder('Vigente')
                    ->badge()
                    ->color(fn ($record): string => $record->reversal ? 'danger' : 'success'),

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
                Tables\Actions\Action::make('anular')
                    ->label('Anular')
                    ->modalHeading('Anular este cobro')
                    ->modalDescription('Se conservará el cobro original y se registrará un reverso contable y de caja. Para cobros en efectivo se requiere una caja abierta.')
                    ->visible(fn ($record): bool => $record->reversal === null)
                    ->form([
                        Forms\Components\Hidden::make('request_key')
                            ->default(fn (): string => (string) Str::uuid()),
                        Forms\Components\Textarea::make('reason')
                            ->label('Motivo')
                            ->required()
                            ->maxLength(2000),
                    ])
                    ->action(function ($record, array $data): void {
                        app(ReceivablePaymentService::class)->reverse(
                            $record,
                            (string) $data['reason'],
                            (string) $data['request_key'],
                        );

                        Notification::make()
                            ->title('Cobro anulado')
                            ->body('Se registró el reverso sin eliminar el historial.')
                            ->success()
                            ->send();
                    }),
            ])
            ->bulkActions([])
            ->emptyStateHeading('Sin cobros registrados')
            ->emptyStateDescription('Usa el botón “Registrar Cobro” para registrar el primer abono.');
    }
}
