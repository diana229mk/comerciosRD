<?php

namespace App\Filament\Resources;

use App\Filament\Concerns\ChecksBusinessModule;
use App\Filament\Resources\ReceivableResource\Pages;
use App\Filament\Resources\ReceivableResource\RelationManagers;
use App\Models\Customer;
use App\Models\Receivable;
use Filament\Forms;
use Filament\Forms\Form;
use Filament\Forms\Get;
use Filament\Forms\Set;
use Filament\Notifications\Notification;
use Filament\Resources\Resource;
use Filament\Tables;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\SoftDeletingScope;

class ReceivableResource extends Resource
{
    use ChecksBusinessModule;

    protected static string $businessModule = 'receivables';

    protected static string $viewPermission = 'view_receivables';

    protected static ?string $createPermission = null;

    protected static ?string $editPermission = null;

    protected static ?string $deletePermission = null;

    protected static ?string $model = Receivable::class;

    protected static ?string $navigationIcon = 'heroicon-o-banknotes';

    protected static ?string $navigationLabel = 'Cuentas por Cobrar';

    protected static ?string $modelLabel = 'Cuenta por Cobrar';

    protected static ?string $pluralModelLabel = 'Cuentas por Cobrar';

    protected static ?string $navigationGroup = 'Finanzas';

    protected static ?int $navigationSort = 2;

    protected static ?string $tenantOwnershipRelationshipName = 'business';

    public static function getNavigationLabel(): string
    {
        return match (filament()->getTenant()?->business_type) {
            'colmado' => 'Fiado / Cuentas por Cobrar',
            'repuestos' => 'Crédito talleres',
            default => 'Cuentas por Cobrar',
        };
    }

    public static function getNavigationGroup(): ?string
    {
        return 'Finanzas';
    }

    public static function form(Form $form): Form
    {
        return $form
            ->schema([
                Forms\Components\Section::make('Información de la Deuda')
                    ->schema([
                        Forms\Components\Select::make('customer_id')
                            ->label('Cliente registrado')
                            ->options(fn () => \App\Models\Customer::query()
                                ->where('is_active', true)
                                ->orderBy('name')
                                ->limit(200)
                                ->pluck('name', 'id')
                                ->all())
                            ->searchable()
                            ->preload()
                            ->nullable()
                            ->live()
                            ->afterStateUpdated(function ($state, Set $set): void {
                                if (! $state) {
                                    return;
                                }
                                $name = Customer::query()->whereKey($state)->value('name');
                                if ($name) {
                                    $set('customer_name', $name);
                                }
                            }),

                        Forms\Components\TextInput::make('customer_name')
                            ->label('Nombre del Cliente')
                            ->required()
                            ->maxLength(255)
                            ->helperText('Obligatorio. Se completa al elegir cliente o puedes escribirlo (fiado).'),

                        Forms\Components\TextInput::make('reference')
                            ->label('Referencia / Factura')
                            ->placeholder('Ej: FAC-2026-0891')
                            ->maxLength(100),

                        Forms\Components\Textarea::make('description')
                            ->label('Descripción')
                            ->required()
                            ->rows(2)
                            ->columnSpanFull(),

                        Forms\Components\TextInput::make('amount')
                            ->label('Monto Total')
                            ->required()
                            ->numeric()
                            ->prefix('RD$')
                            ->minValue(0)
                            ->step(0.01)
                            ->live(onBlur: true)
                            ->afterStateUpdated(function ($state, Get $get, Set $set): void {
                                $paid = (float) ($get('paid_amount') ?? 0);
                                $set('balance', max(0, (float) $state - $paid));
                            }),

                        Forms\Components\DatePicker::make('due_date')
                            ->label('Fecha de Vencimiento')
                            ->required()
                            ->native(false)
                            ->default(now()->addDays(15)),

                        Forms\Components\Select::make('status')
                            ->label('Estado')
                            ->options([
                                'pendiente' => 'Pendiente',
                                'parcial' => 'Pago Parcial',
                                'pagada' => 'Pagada',
                                'vencida' => 'Vencida',
                            ])
                            ->default('pendiente')
                            ->disabled()
                            ->dehydrated()
                            ->helperText('Se actualiza automáticamente con los cobros y el vencimiento.'),
                    ])
                    ->columns(2),

                Forms\Components\Section::make('Cobros y Saldo')
                    ->schema([
                        Forms\Components\TextInput::make('paid_amount')
                            ->label('Monto Cobrado')
                            ->numeric()
                            ->prefix('RD$')
                            ->default(0)
                            ->disabled()
                            ->dehydrated()
                            ->helperText('Se calcula con el historial de cobros (botón Registrar Cobro).'),

                        Forms\Components\TextInput::make('balance')
                            ->label('Saldo Pendiente')
                            ->numeric()
                            ->prefix('RD$')
                            ->disabled()
                            ->dehydrated()
                            ->default(0),

                        Forms\Components\Textarea::make('notes')
                            ->label('Notas')
                            ->rows(2)
                            ->columnSpanFull(),
                    ])
                    ->columns(2),
            ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                Tables\Columns\TextColumn::make('customer_name')
                    ->label('Cliente')
                    ->searchable()
                    ->weight('bold'),

                Tables\Columns\TextColumn::make('reference')
                    ->label('Factura')
                    ->searchable()
                    ->toggleable(),

                Tables\Columns\TextColumn::make('amount')
                    ->label('Monto')
                    ->money('DOP')
                    ->sortable(),

                Tables\Columns\TextColumn::make('paid_amount')
                    ->label('Cobrado')
                    ->money('DOP')
                    ->color('success')
                    ->toggleable(),

                Tables\Columns\TextColumn::make('balance')
                    ->label('Saldo')
                    ->money('DOP')
                    ->color(fn ($state) => (float) $state > 0 ? 'danger' : 'success')
                    ->weight('bold'),

                Tables\Columns\TextColumn::make('due_date')
                    ->label('Vencimiento')
                    ->date('d/m/Y')
                    ->sortable()
                    ->color(fn ($state) => $state && $state < now()->startOfDay() ? 'danger' : 'gray'),

                Tables\Columns\TextColumn::make('status')
                    ->label('Estado')
                    ->badge()
                    ->formatStateUsing(fn (string $state): string => match ($state) {
                        'pendiente' => 'Pendiente',
                        'parcial' => 'Pago Parcial',
                        'pagada' => 'Pagada',
                        'vencida' => 'Vencida',
                        default => $state,
                    })
                    ->color(fn (string $state): string => match ($state) {
                        'pendiente' => 'warning',
                        'parcial' => 'info',
                        'pagada' => 'success',
                        'vencida' => 'danger',
                        default => 'gray',
                    }),
            ])
            ->defaultSort('due_date', 'asc')
            ->filters([
                Tables\Filters\SelectFilter::make('status')
                    ->label('Estado')
                    ->options([
                        'pendiente' => 'Pendiente',
                        'parcial' => 'Parcial',
                        'pagada' => 'Pagada',
                        'vencida' => 'Vencida',
                    ]),

                Tables\Filters\SelectFilter::make('customer_id')
                    ->label('Cliente')
                    ->relationship('customer', 'name')
                    ->searchable()
                    ->preload(),

                Tables\Filters\Filter::make('vencidas')
                    ->label('Solo vencidas')
                    ->query(fn (Builder $query): Builder => $query->where('status', 'vencida'))
                    ->toggle(),

                Tables\Filters\Filter::make('proximas')
                    ->label('Vencen en 7 días')
                    ->query(fn (Builder $query): Builder => $query->dueSoon(7))
                    ->toggle(),
            ])
            ->actions([
                static::registerCollectionAction(),
                Tables\Actions\EditAction::make(),
                Tables\Actions\DeleteAction::make(),
            ])
            ->bulkActions([
                Tables\Actions\BulkActionGroup::make([
                    Tables\Actions\DeleteBulkAction::make(),
                ]),
            ])
            ->emptyStateHeading('Sin cuentas por cobrar')
            ->emptyStateDescription('Registra ventas a crédito / fiado y controla los cobros pendientes.');
    }

    /**
     * @return array<int, Forms\Components\Component>
     */
    public static function registerCollectionFormSchema(?Receivable $record = null): array
    {
        $balance = (float) ($record?->balance ?? 0);

        return [
            Forms\Components\Placeholder::make('info')
                ->label('Saldo pendiente')
                ->content('RD$ '.number_format($balance, 2)),

            Forms\Components\TextInput::make('amount')
                ->label('Monto cobrado')
                ->numeric()
                ->prefix('RD$')
                ->required()
                ->minValue(0.01)
                ->default($balance),

            Forms\Components\Select::make('payment_method')
                ->label('Método de cobro')
                ->options([
                    'efectivo' => 'Efectivo',
                    'transferencia' => 'Transferencia',
                    'cheque' => 'Cheque',
                    'tarjeta' => 'Tarjeta',
                ])
                ->required()
                ->default('efectivo')
                ->native(false)
                ->helperText('Si es efectivo, se suma a la caja abierta (si hay una).'),

            Forms\Components\DatePicker::make('payment_date')
                ->label('Fecha de cobro')
                ->required()
                ->default(now())
                ->native(false),

            Forms\Components\TextInput::make('reference')
                ->label('Referencia')
                ->placeholder('Nº de transferencia, etc.')
                ->maxLength(100),

            Forms\Components\Textarea::make('notes')
                ->label('Notas')
                ->rows(2),
        ];
    }

    /**
     * @param  array<string, mixed>  $data
     */
    public static function processRegisterCollection(Receivable $record, array $data): void
    {
        $amount = (float) ($data['amount'] ?? 0);

        if ($amount > (float) $record->balance + 0.001) {
            Notification::make()
                ->title('El monto supera el saldo pendiente')
                ->body('Saldo actual: RD$ '.number_format((float) $record->balance, 2))
                ->danger()
                ->send();

            return;
        }

        $method = (string) ($data['payment_method'] ?? 'efectivo');

        $record->registerPayment(
            amount: $amount,
            paymentMethod: $method,
            paymentDate: isset($data['payment_date']) ? (string) $data['payment_date'] : null,
            reference: $data['reference'] ?? null,
            notes: $data['notes'] ?? null,
        );

        $cashNote = in_array(strtolower($method), ['efectivo', 'cash'], true)
            ? ' · Sumado a caja (si hay sesión abierta)'
            : '';

        Notification::make()
            ->title('Cobro registrado correctamente')
            ->body(
                'Cobro: RD$ '.number_format($amount, 2)
                .' · Saldo: RD$ '.number_format((float) $record->fresh()->balance, 2)
                .$cashNote
            )
            ->success()
            ->send();
    }

    public static function registerCollectionAction(): Tables\Actions\Action
    {
        return Tables\Actions\Action::make('registrar_cobro')
            ->label('Registrar Cobro')
            ->icon('heroicon-o-banknotes')
            ->color('success')
            ->modalHeading('Registrar cobro')
            ->modalWidth('lg')
            ->visible(fn (Receivable $record): bool => $record->status !== 'pagada' && (float) $record->balance > 0)
            ->form(fn (Receivable $record): array => static::registerCollectionFormSchema($record))
            ->action(function (Receivable $record, array $data): void {
                try {
                    static::processRegisterCollection($record, $data);
                } catch (\Throwable $e) {
                    Notification::make()
                        ->title('No se pudo registrar el cobro')
                        ->body($e->getMessage())
                        ->danger()
                        ->send();
                }
            });
    }

    public static function getRelations(): array
    {
        return [
            RelationManagers\PaymentsRelationManager::class,
        ];
    }

    public static function getPages(): array
    {
        return [
            'index' => Pages\ListReceivables::route('/'),
            'create' => Pages\CreateReceivable::route('/create'),
            'edit' => Pages\EditReceivable::route('/{record}/edit'),
        ];
    }

    public static function getEloquentQuery(): Builder
    {
        return parent::getEloquentQuery()
            ->withoutGlobalScopes([
                SoftDeletingScope::class,
            ]);
    }

    public static function canViewAny(): bool
    {
        if (! static::tenantHasModule()) {
            return false;
        }

        return auth()->user()?->canPerform('view_receivables') ?? false;
    }

    public static function canCreate(): bool
    {
        if (! static::tenantHasModule()) {
            return false;
        }

        return auth()->user()?->hasRoleInBusiness(['owner', 'admin', 'supervisor']) ?? false;
    }

    public static function canEdit($record): bool
    {
        if (! static::tenantHasModule()) {
            return false;
        }

        return auth()->user()?->hasRoleInBusiness(['owner', 'admin', 'supervisor']) ?? false;
    }

    public static function canDelete($record): bool
    {
        if (! static::tenantHasModule()) {
            return false;
        }

        return auth()->user()?->hasRoleInBusiness(['owner', 'admin']) ?? false;
    }
}
