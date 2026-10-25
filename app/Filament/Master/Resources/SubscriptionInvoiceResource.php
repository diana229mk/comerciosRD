<?php

namespace App\Filament\Master\Resources;

use App\Enums\SubscriptionInvoiceStatus;
use App\Enums\SubscriptionPlan;
use App\Filament\Master\Resources\SubscriptionInvoiceResource\Pages;
use App\Models\MasterAuditLog;
use App\Models\SubscriptionInvoice;
use App\Services\SubscriptionBillingService;
use Filament\Forms;
use Filament\Forms\Form;
use Filament\Resources\Resource;
use Filament\Tables;
use Filament\Tables\Table;

class SubscriptionInvoiceResource extends Resource
{
    protected static ?string $model = SubscriptionInvoice::class;

    protected static ?string $navigationIcon = 'heroicon-o-banknotes';

    protected static ?string $navigationLabel = 'Historial de cuotas';

    protected static ?string $modelLabel = 'Cuota';

    protected static ?string $pluralModelLabel = 'Suscripciones';

    protected static ?string $navigationGroup = 'ComerciosRD';

    protected static ?int $navigationSort = 3;

    public static function form(Form $form): Form
    {
        return $form->schema([
            Forms\Components\Select::make('business_id')
                ->label('Negocio')
                ->relationship('business', 'name')
                ->searchable()
                ->required()
                ->disabledOn('edit'),
            Forms\Components\Select::make('plan')
                ->label('Plan')
                ->options(SubscriptionPlan::options())
                ->required(),
            Forms\Components\TextInput::make('extra_seats')
                ->label('Asientos extra')
                ->numeric()
                ->minValue(0)
                ->default(0),
            Forms\Components\TextInput::make('amount')
                ->label('Monto (DOP)')
                ->numeric()
                ->required()
                ->minValue(0),
            Forms\Components\DatePicker::make('period_start')->label('Periodo desde')->required(),
            Forms\Components\DatePicker::make('period_end')->label('Periodo hasta')->required(),
            Forms\Components\DatePicker::make('due_at')->label('Vence')->required(),
            Forms\Components\Select::make('status')
                ->label('Estado')
                ->options(SubscriptionInvoiceStatus::options())
                ->required(),
            Forms\Components\DateTimePicker::make('paid_at')->label('Pagada el'),
            Forms\Components\Textarea::make('notes')->label('Notas')->columnSpanFull(),
        ])->columns(2);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                Tables\Columns\TextColumn::make('business.name')
                    ->label('Negocio')
                    ->searchable()
                    ->sortable(),
                Tables\Columns\TextColumn::make('plan')
                    ->label('Plan')
                    ->badge()
                    ->formatStateUsing(fn ($state) => $state instanceof SubscriptionPlan
                        ? $state->label()
                        : SubscriptionPlan::tryFromMixed($state)->label()),
                Tables\Columns\TextColumn::make('extra_seats')
                    ->label('Extra')
                    ->toggleable(),
                Tables\Columns\TextColumn::make('amount')
                    ->label('Monto')
                    ->formatStateUsing(fn ($state) => 'RD$ '.number_format((int) $state, 0))
                    ->sortable(),
                Tables\Columns\TextColumn::make('period_start')
                    ->label('Periodo')
                    ->formatStateUsing(function (SubscriptionInvoice $record): string {
                        return $record->period_start?->format('d/m').' – '.$record->period_end?->format('d/m/Y');
                    }),
                Tables\Columns\TextColumn::make('due_at')
                    ->label('Vence')
                    ->date('d/m/Y')
                    ->sortable(),
                Tables\Columns\TextColumn::make('status')
                    ->label('Estado')
                    ->badge()
                    ->formatStateUsing(fn ($state) => $state instanceof SubscriptionInvoiceStatus
                        ? $state->label()
                        : (string) $state)
                    ->color(fn ($state) => $state instanceof SubscriptionInvoiceStatus
                        ? $state->color()
                        : 'gray'),
                Tables\Columns\TextColumn::make('paid_at')
                    ->label('Pagada')
                    ->dateTime('d/m/Y')
                    ->placeholder('—'),
            ])
            ->defaultSort('due_at', 'desc')
            ->filters([
                Tables\Filters\SelectFilter::make('status')
                    ->options(SubscriptionInvoiceStatus::options()),
                Tables\Filters\SelectFilter::make('plan')
                    ->options(
                        collect(SubscriptionPlan::cases())
                            ->mapWithKeys(fn (SubscriptionPlan $p) => [$p->value => $p->label()])
                            ->all()
                    ),
            ])
            ->actions([
                Tables\Actions\Action::make('pagar')
                    ->label('Marcar pagada')
                    ->icon('heroicon-o-check-circle')
                    ->color('success')
                    ->visible(fn (SubscriptionInvoice $record) => $record->isOpen())
                    ->requiresConfirmation()
                    ->action(function (SubscriptionInvoice $record): void {
                        app(SubscriptionBillingService::class)->markPaid($record);
                        MasterAuditLog::log('subscription_paid', $record, ['status' => 'open'], ['status' => 'paid']);
                    }),
                Tables\Actions\EditAction::make(),
            ]);
    }

    public static function getPages(): array
    {
        return [
            'index' => Pages\ListSubscriptionInvoices::route('/'),
            'create' => Pages\CreateSubscriptionInvoice::route('/create'),
            'edit' => Pages\EditSubscriptionInvoice::route('/{record}/edit'),
        ];
    }
}
