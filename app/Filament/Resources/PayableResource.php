<?php

namespace App\Filament\Resources;

use App\Filament\Concerns\ChecksBusinessModule;
use App\Filament\Resources\PayableResource\Pages;
use App\Filament\Resources\PayableResource\RelationManagers;
use App\Models\Payable;
use Filament\Facades\Filament;
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

class PayableResource extends Resource
{
    use ChecksBusinessModule;

    protected static string $businessModule = 'payables';

    protected static string $viewPermission = 'view_payables';

    protected static ?string $createPermission = null;

    protected static ?string $editPermission = null;

    protected static ?string $deletePermission = null;

    protected static ?string $model = Payable::class;

    protected static ?string $navigationIcon = 'heroicon-o-credit-card';

    protected static ?string $navigationLabel = 'Cuentas por Pagar';

    protected static ?string $modelLabel = 'Cuenta por Pagar';

    protected static ?string $pluralModelLabel = 'Cuentas por Pagar';

    protected static ?string $navigationGroup = 'Finanzas';

    protected static ?int $navigationSort = 3;

    protected static ?string $tenantOwnershipRelationshipName = 'business';

    public static function form(Form $form): Form
    {
        return $form
            ->schema([
                Forms\Components\Section::make('Información de la Deuda')
                    ->schema([
                        Forms\Components\Select::make('supplier_id')
                            ->label('Proveedor (catálogo)')
                            ->options(fn () => \App\Models\Supplier::query()
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
                                $name = \App\Models\Supplier::query()->whereKey($state)->value('name');
                                if ($name) {
                                    $set('supplier_name', $name);
                                }
                            }),

                        Forms\Components\TextInput::make('supplier_name')
                            ->label('Nombre del Proveedor')
                            ->required()
                            ->maxLength(255)
                            ->helperText('Se rellena al elegir del catálogo, o escríbelo manualmente.'),

                        Forms\Components\TextInput::make('reference')
                            ->label('Referencia / Factura')
                            ->placeholder('Ej: FAC-2026-0452')
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
                            ->helperText('Se actualiza automáticamente con los pagos y el vencimiento.'),
                    ])
                    ->columns(2),

                Forms\Components\Section::make('Pagos y Saldo')
                    ->schema([
                        Forms\Components\TextInput::make('paid_amount')
                            ->label('Monto Pagado')
                            ->numeric()
                            ->prefix('RD$')
                            ->default(0)
                            ->disabled()
                            ->dehydrated()
                            ->helperText('Se calcula con el historial de pagos (botón Registrar Pago).'),

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

                        Forms\Components\Placeholder::make('invoice_hint')
                            ->label('Factura escaneada')
                            ->content(fn (?Payable $record): string => $record && filled($record->invoice_file)
                                ? 'Ya hay una factura adjunta. Puedes reemplazarla al registrar un pago.'
                                : 'La factura se sube al registrar un pago (botón “Registrar Pago”).')
                            ->visibleOn('edit'),
                    ])
                    ->columns(2),
            ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                Tables\Columns\TextColumn::make('supplier_name')
                    ->label('Proveedor')
                    ->searchable()
                    ->weight('bold'),

                Tables\Columns\TextColumn::make('reference')
                    ->label('Factura')
                    ->searchable()
                    ->toggleable(),

                Tables\Columns\IconColumn::make('invoice_file')
                    ->label('Factura')
                    ->boolean()
                    ->getStateUsing(fn (Payable $record): bool => filled($record->invoice_file))
                    ->trueIcon('heroicon-o-document')
                    ->falseIcon('heroicon-o-x-mark')
                    ->trueColor('info')
                    ->falseColor('gray'),

                Tables\Columns\TextColumn::make('amount')
                    ->label('Monto')
                    ->money('DOP')
                    ->sortable(),

                Tables\Columns\TextColumn::make('paid_amount')
                    ->label('Pagado')
                    ->money('DOP')
                    ->color('success'),

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

                Tables\Filters\Filter::make('vencidas')
                    ->label('Solo vencidas')
                    ->query(fn (Builder $query): Builder => $query->where('status', 'vencida'))
                    ->toggle(),
            ])
            ->actions([
                static::registerPaymentAction(),

                Tables\Actions\Action::make('ver_factura')
                    ->label('Ver factura')
                    ->icon('heroicon-o-document')
                    ->color('gray')
                    ->url(fn (Payable $record): ?string => $record->invoice_file && Filament::getTenant()
                        ? route('private-documents.payables.invoice', [
                            'tenant' => Filament::getTenant()->slug,
                            'payable' => $record->getKey(),
                        ])
                        : null)
                    ->openUrlInNewTab()
                    ->visible(fn (Payable $record): bool => filled($record->invoice_file)),

                Tables\Actions\EditAction::make(),
                Tables\Actions\DeleteAction::make(),
            ])
            ->bulkActions([
                Tables\Actions\BulkActionGroup::make([
                    Tables\Actions\DeleteBulkAction::make(),
                ]),
            ])
            ->emptyStateHeading('Sin cuentas por pagar')
            ->emptyStateDescription('Registra facturas de proveedores y controla vencimientos.');
    }

    /**
     * Campos del modal “Registrar Pago” (incluye factura PDF/imagen).
     *
     * @return array<int, Forms\Components\Component>
     */
    public static function registerPaymentFormSchema(?Payable $record = null): array
    {
        $balance = (float) ($record?->balance ?? 0);
        $hasInvoice = filled($record?->invoice_file);

        return [
            Forms\Components\Placeholder::make('info')
                ->label('Saldo pendiente')
                ->content('RD$ '.number_format($balance, 2)),

            Forms\Components\TextInput::make('amount')
                ->label('Monto a pagar')
                ->numeric()
                ->prefix('RD$')
                ->required()
                ->minValue(0.01)
                ->default($balance),

            Forms\Components\Select::make('payment_method')
                ->label('Método de pago')
                ->options([
                    'efectivo' => 'Efectivo',
                    'transferencia' => 'Transferencia',
                    'cheque' => 'Cheque',
                    'tarjeta' => 'Tarjeta',
                ])
                ->required()
                ->default('efectivo')
                ->native(false),

            Forms\Components\DatePicker::make('payment_date')
                ->label('Fecha de pago')
                ->required()
                ->default(now())
                ->native(false),

            Forms\Components\TextInput::make('reference')
                ->label('Referencia')
                ->placeholder('Nº de transferencia, cheque, etc.')
                ->maxLength(100),

            Forms\Components\Textarea::make('notes')
                ->label('Notas')
                ->rows(2),

            Forms\Components\Section::make('Factura Escaneada')
                ->description('Aquí se sube el PDF o la foto de la factura del proveedor (al pagar).')
                ->icon('heroicon-o-document-arrow-up')
                ->schema([
                    Forms\Components\Placeholder::make('invoice_current')
                        ->label('Estado')
                        ->content($hasInvoice
                            ? 'Ya hay una factura adjunta. Si subes otra, se reemplaza.'
                            : 'Aún no hay factura. Adjunta el PDF o imagen ahora.')
                        ->columnSpanFull(),

                    Forms\Components\FileUpload::make('invoice_file')
                        ->label('Subir factura (PDF o imagen)')
                        ->disk('private')
                        ->directory(fn (): string => app(\App\Services\PrivateDocumentStorage::class)
                            ->directoryForCurrentBusiness('payables/invoices'))
                        ->visibility('private')
                        ->acceptedFileTypes([
                            'application/pdf',
                            'application/x-pdf',
                            'image/jpeg',
                            'image/jpg',
                            'image/pjpeg',
                            'image/png',
                            'image/webp',
                            'image/gif',
                        ])
                        ->maxSize(10240)
                        ->downloadable(false)
                        ->openable(false)
                        ->previewable(false)
                        ->required(fn (): bool => ! $hasInvoice)
                        ->helperText(
                            $hasInvoice
                                ? 'Opcional: deja vacío para conservar la factura actual.'
                                : 'Obligatorio en el primer pago: PDF, JPG, PNG o WEBP (máx. 10 MB).'
                        )
                        ->columnSpanFull(),
                ]),
        ];
    }

    /**
     * Procesa el pago + opcionalmente guarda la factura subida.
     *
     * @param  array<string, mixed>  $data
     */
    public static function processRegisterPayment(Payable $record, array $data): void
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

        // Guardar factura si se subió en este pago
        $invoice = $data['invoice_file'] ?? null;
        if (is_array($invoice)) {
            $invoice = $invoice[0] ?? null;
        }
        if (is_string($invoice) && $invoice !== '') {
            if ($invoice !== $record->invoice_file) {
                app(\App\Services\PrivateDocumentStorage::class)->assertStoredForBusiness(
                    $invoice,
                    'payables/invoices',
                    (string) $record->business_id,
                    'invoice_file',
                );
                $record->forceFill(['invoice_file' => $invoice])->saveQuietly();
            }
        }

        $record->registerPayment(
            amount: $amount,
            paymentMethod: (string) ($data['payment_method'] ?? 'efectivo'),
            paymentDate: isset($data['payment_date'])
                ? (string) $data['payment_date']
                : null,
            reference: $data['reference'] ?? null,
            notes: $data['notes'] ?? null,
        );

        $hasInvoice = filled($record->fresh()->invoice_file);

        Notification::make()
            ->title('Pago registrado correctamente')
            ->body(
                'Pago: RD$ '.number_format($amount, 2)
                .' · Saldo: RD$ '.number_format((float) $record->fresh()->balance, 2)
                .($hasInvoice ? ' · Factura guardada' : '')
            )
            ->success()
            ->send();
    }

    /**
     * Acción de tabla: registrar pago + factura.
     */
    public static function registerPaymentAction(): Tables\Actions\Action
    {
        return Tables\Actions\Action::make('registrar_pago')
            ->label('Registrar Pago')
            ->icon('heroicon-o-banknotes')
            ->color('success')
            ->modalHeading('Registrar pago y factura')
            ->modalDescription('Ingresa el monto del pago y adjunta la factura escaneada (PDF o imagen).')
            ->modalWidth('lg')
            ->visible(fn (Payable $record): bool => $record->status !== 'pagada' && (float) $record->balance > 0)
            ->form(fn (Payable $record): array => static::registerPaymentFormSchema($record))
            ->action(function (Payable $record, array $data): void {
                try {
                    static::processRegisterPayment($record, $data);
                } catch (\Throwable $e) {
                    Notification::make()
                        ->title('No se pudo registrar el pago')
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
            'index' => Pages\ListPayables::route('/'),
            'create' => Pages\CreatePayable::route('/create'),
            'edit' => Pages\EditPayable::route('/{record}/edit'),
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

        return auth()->user()?->canPerform('view_payables') ?? false;
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
