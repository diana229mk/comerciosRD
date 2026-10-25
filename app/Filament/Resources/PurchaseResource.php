<?php

namespace App\Filament\Resources;

use App\Enums\PurchasePaymentStatus;
use App\Enums\PurchaseStatus;
use App\Filament\Resources\PurchaseResource\Pages;
use App\Models\Product;
use App\Models\Purchase;
use App\Models\Supplier;
use App\Services\PurchaseService;
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

class PurchaseResource extends Resource
{
    use \App\Filament\Concerns\ChecksBusinessModule;

    protected static ?string $model = Purchase::class;

    protected static string $businessModule = 'purchases';

    protected static string $viewPermission = 'view_purchases';

    protected static string $createPermission = 'create_purchases';

    protected static string $editPermission = 'edit_purchases';

    protected static ?string $deletePermission = null;


    protected static ?string $navigationIcon = 'heroicon-o-shopping-bag';

    protected static ?string $navigationLabel = 'Compras';

    protected static ?string $modelLabel = 'Compra';

    protected static ?string $pluralModelLabel = 'Compras';

    protected static ?string $navigationGroup = 'Compras';

    protected static ?int $navigationSort = 2;

    protected static ?string $recordTitleAttribute = 'purchase_number';

    /** Ownership multi-tenant (Filament tenant → business) */
    protected static ?string $tenantOwnershipRelationshipName = 'business';

    public static function form(Form $form): Form
    {
        return $form
            ->schema([
                Forms\Components\Section::make('Información de la Compra')
                    ->schema([
                        Forms\Components\Select::make('supplier_id')
                            ->label('Proveedor')
                            ->relationship(
                                name: 'supplier',
                                titleAttribute: 'name',
                                modifyQueryUsing: fn (Builder $query): Builder => $query
                                    ->where('is_active', true)
                                    ->orderBy('name'),
                            )
                            ->searchable()
                            ->preload()
                            ->createOptionForm([
                                Forms\Components\TextInput::make('name')
                                    ->required()
                                    ->label('Nombre'),
                                Forms\Components\TextInput::make('phone')
                                    ->label('Teléfono'),
                                Forms\Components\TextInput::make('rnc')
                                    ->label('RNC'),
                            ])
                            ->createOptionUsing(function (array $data): string {
                                $supplier = Supplier::query()->create([
                                    'name' => $data['name'],
                                    'phone' => $data['phone'] ?? null,
                                    'rnc' => $data['rnc'] ?? null,
                                    'is_active' => true,
                                ]);

                                return (string) $supplier->getKey();
                            })
                            ->live()
                            ->afterStateUpdated(function ($state, Set $set): void {
                                if (! $state) {
                                    return;
                                }
                                $supplier = Supplier::query()->find($state);
                                if ($supplier) {
                                    $set('supplier_name', $supplier->name);
                                }
                            }),

                        Forms\Components\TextInput::make('supplier_name')
                            ->label('Nombre del Proveedor (si no está registrado)')
                            ->maxLength(255),

                        Forms\Components\TextInput::make('invoice_number')
                            ->label('Número de Factura del Proveedor')
                            ->maxLength(100),

                        Forms\Components\DatePicker::make('purchase_date')
                            ->label('Fecha de Compra')
                            ->required()
                            ->default(now())
                            ->native(false),

                        Forms\Components\DatePicker::make('due_date')
                            ->label('Fecha de Vencimiento')
                            ->native(false)
                            ->default(now()->addDays(15)),

                        Forms\Components\Select::make('status')
                            ->label('Estado')
                            ->options(PurchaseStatus::options())
                            ->default(PurchaseStatus::Borrador->value)
                            ->required()
                            ->native(false)
                            ->disabled(fn (?Purchase $record): bool => $record?->isReceived() ?? false)
                            ->dehydrated()
                            ->helperText('Usa “Marcar como Recibida” en la lista para ingresar stock.'),

                        Forms\Components\Select::make('payment_status')
                            ->label('Estado de Pago')
                            ->options(PurchasePaymentStatus::options())
                            ->default(PurchasePaymentStatus::Pendiente->value)
                            ->required()
                            ->native(false),

                        Forms\Components\TextInput::make('amount_paid')
                            ->label('Monto pagado ahora')
                            ->numeric()
                            ->prefix('RD$')
                            ->default(0)
                            ->minValue(0)
                            ->visible(fn (Get $get): bool => in_array(
                                $get('payment_status'),
                                [
                                    PurchasePaymentStatus::Parcial->value,
                                    PurchasePaymentStatus::Pagada->value,
                                    'parcial',
                                    'pagada',
                                ],
                                true
                            )),
                    ])
                    ->columns(2),

                Forms\Components\Section::make('Productos Comprados')
                    ->schema([
                        Forms\Components\Repeater::make('items')
                            ->relationship()
                            ->schema([
                                Forms\Components\Select::make('product_id')
                                    ->label('Producto')
                                    ->options(fn () => Product::query()
                                        ->where('is_active', true)
                                        ->orderBy('name')
                                        ->pluck('name', 'id'))
                                    ->searchable()
                                    ->required()
                                    ->live()
                                    ->afterStateUpdated(function ($state, Set $set): void {
                                        if (! $state) {
                                            return;
                                        }
                                        $product = Product::query()->find($state);
                                        if (! $product) {
                                            return;
                                        }
                                        $cost = (float) ($product->cost ?? 0);
                                        $set('product_name', $product->name);
                                        $set('unit_cost', $cost);
                                        $set('quantity', 1);
                                        $set('subtotal', number_format($cost, 2, '.', ''));
                                    })
                                    ->columnSpan(4),

                                Forms\Components\TextInput::make('product_name')
                                    ->label('Nombre')
                                    ->disabled()
                                    ->dehydrated()
                                    ->columnSpan(3),

                                Forms\Components\TextInput::make('unit_cost')
                                    ->label('Costo Unit.')
                                    ->numeric()
                                    ->prefix('RD$')
                                    ->required()
                                    ->live(onBlur: true)
                                    ->afterStateUpdated(fn ($state, Get $get, Set $set) => self::calculateItemSubtotal($get, $set))
                                    ->columnSpan(2),

                                Forms\Components\TextInput::make('quantity')
                                    ->label('Cantidad')
                                    ->numeric()
                                    ->default(1)
                                    ->minValue(0.001)
                                    ->required()
                                    ->live(onBlur: true)
                                    ->afterStateUpdated(fn ($state, Get $get, Set $set) => self::calculateItemSubtotal($get, $set))
                                    ->columnSpan(1),

                                Forms\Components\TextInput::make('subtotal')
                                    ->label('Subtotal')
                                    ->numeric()
                                    ->prefix('RD$')
                                    ->disabled()
                                    ->dehydrated()
                                    ->columnSpan(2),

                                // ========== LOTE FARMACIA ==========
                                Forms\Components\TextInput::make('batch_code')
                                    ->label('N° de lote')
                                    ->maxLength(60)
                                    ->placeholder('Ej: L2026-08-A')
                                    ->helperText('Opcional pero recomendado')
                                    ->visible(fn (): bool => self::tenantIsPharmacy())
                                    ->columnSpan(6),

                                Forms\Components\DatePicker::make('expires_at')
                                    ->label('Fecha de vencimiento')
                                    ->native(false)
                                    ->required(fn (): bool => self::tenantIsPharmacy())
                                    ->minDate(now()->subYears(2))
                                    ->helperText('Requerido en farmacia — se creará el lote al recibir')
                                    ->visible(fn (): bool => self::tenantIsPharmacy())
                                    ->columnSpan(6),
                            ])
                            ->columns(12)
                            ->defaultItems(1)
                            ->addActionLabel('Agregar Producto')
                            ->reorderable(false)
                            ->columnSpanFull()
                            ->live()
                            ->afterStateUpdated(fn (Get $get, Set $set) => self::calculateTotals($get, $set)),
                    ]),

                Forms\Components\Section::make('Totales')
                    ->schema([
                        Forms\Components\TextInput::make('subtotal')
                            ->label('Subtotal')
                            ->numeric()
                            ->prefix('RD$')
                            ->disabled()
                            ->dehydrated()
                            ->default(0),

                        Forms\Components\TextInput::make('tax')
                            ->label('ITBIS (18%)')
                            ->numeric()
                            ->prefix('RD$')
                            ->disabled()
                            ->dehydrated()
                            ->default(0),

                        Forms\Components\TextInput::make('total')
                            ->label('Total')
                            ->numeric()
                            ->prefix('RD$')
                            ->disabled()
                            ->dehydrated()
                            ->default(0)
                            ->extraAttributes(['class' => 'font-bold text-lg']),

                        Forms\Components\Textarea::make('notes')
                            ->label('Notas')
                            ->rows(2)
                            ->columnSpanFull(),
                    ])
                    ->columns(3),
            ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                Tables\Columns\TextColumn::make('purchase_number')
                    ->label('Número')
                    ->searchable()
                    ->weight('bold')
                    ->copyable(),

                Tables\Columns\TextColumn::make('supplier_name')
                    ->label('Proveedor')
                    ->searchable()
                    ->sortable(),

                Tables\Columns\TextColumn::make('invoice_number')
                    ->label('Factura Proveedor')
                    ->toggleable(),

                Tables\Columns\TextColumn::make('total')
                    ->label('Total')
                    ->money('DOP')
                    ->sortable(),

                Tables\Columns\TextColumn::make('status')
                    ->label('Estado')
                    ->badge()
                    ->formatStateUsing(function ($state): string {
                        if ($state instanceof PurchaseStatus) {
                            return $state->label();
                        }

                        return match ((string) $state) {
                            'borrador' => 'Borrador',
                            'recibida' => 'Recibida',
                            'anulada' => 'Anulada',
                            default => (string) $state,
                        };
                    })
                    ->color(function ($state): string {
                        if ($state instanceof PurchaseStatus) {
                            return $state->color();
                        }

                        return match ((string) $state) {
                            'borrador' => 'gray',
                            'recibida' => 'success',
                            'anulada' => 'danger',
                            default => 'gray',
                        };
                    }),

                Tables\Columns\TextColumn::make('payment_status')
                    ->label('Pago')
                    ->badge()
                    ->formatStateUsing(function ($state): string {
                        if ($state instanceof PurchasePaymentStatus) {
                            return $state->label();
                        }

                        return match ((string) $state) {
                            'pendiente' => 'Pendiente',
                            'parcial' => 'Parcial',
                            'pagada' => 'Pagada',
                            default => (string) $state,
                        };
                    })
                    ->color(function ($state): string {
                        $value = $state instanceof PurchasePaymentStatus
                            ? $state->value
                            : (string) $state;

                        return match ($value) {
                            'pendiente' => 'warning',
                            'parcial' => 'info',
                            'pagada' => 'success',
                            default => 'gray',
                        };
                    }),

                Tables\Columns\TextColumn::make('purchase_date')
                    ->label('Fecha')
                    ->date('d/m/Y')
                    ->sortable(),

                \App\Filament\Support\BranchTableHelpers::column(),
            ])
            ->defaultSort('purchase_date', 'desc')
            ->filters([
                Tables\Filters\SelectFilter::make('status')
                    ->options(PurchaseStatus::options()),
                Tables\Filters\SelectFilter::make('payment_status')
                    ->options(PurchasePaymentStatus::options()),
                \App\Filament\Support\BranchTableHelpers::filter(),
                Tables\Filters\TrashedFilter::make(),
            ])
            ->actions([
                Tables\Actions\Action::make('marcar_recibida')
                    ->label('Marcar como Recibida')
                    ->icon('heroicon-o-check-circle')
                    ->color('success')
                    ->requiresConfirmation()
                    ->modalHeading('Marcar compra como recibida')
                    ->modalDescription('Se actualizará el inventario y, si aplica, se creará una cuenta por pagar.')
                    ->visible(fn (Purchase $record): bool => $record->isDraft())
                    ->action(function (Purchase $record): void {
                        try {
                            $tenant = filament()->getTenant();
                            if ($tenant instanceof \App\Models\Business && $tenant->usesAdvancedInventory()) {
                                app(PurchaseService::class)->receiveWithAvco($record);
                            } else {
                                app(PurchaseService::class)->receive($record);
                            }

                            Notification::make()
                                ->title('Compra marcada como recibida')
                                ->body('El inventario ha sido actualizado.')
                                ->success()
                                ->send();
                        } catch (\Throwable $e) {
                            Notification::make()
                                ->title('No se pudo recibir la compra')
                                ->body($e->getMessage())
                                ->danger()
                                ->send();
                        }
                    }),

                Tables\Actions\Action::make('recepcion_parcial')
                    ->label('Recepción parcial')
                    ->icon('heroicon-o-inbox-arrow-down')
                    ->color('primary')
                    ->visible(fn (Purchase $record): bool => $record->isDraft()
                        && (filament()->getTenant()?->usesAdvancedInventory() ?? false))
                    ->form(fn (Purchase $record) => collect($record->items)->map(function ($it) {
                        $pending = (float) $it->quantity - (float) ($it->received_quantity ?? 0);
                        return \Filament\Forms\Components\TextInput::make('item_'.$it->id)
                            ->label($it->product_name.' (pendiente: '.$pending.')')
                            ->numeric()->minValue(0)->maxValue($pending)->default(0);
                    })->all())
                    ->action(function (Purchase $record, array $data): void {
                        try {
                            $qtys = [];
                            foreach ($record->items as $it) {
                                $qty = (float) ($data['item_'.$it->id] ?? 0);
                                if ($qty > 0) $qtys[$it->id] = $qty;
                            }
                            if (empty($qtys)) {
                                throw new \RuntimeException('Ingresa al menos una cantidad a recibir.');
                            }
                            app(PurchaseService::class)->receivePartial($record, $qtys);
                            Notification::make()->title('Recepción parcial aplicada')->success()->send();
                        } catch (\Throwable $e) {
                            Notification::make()->title('Error')->body($e->getMessage())->danger()->send();
                        }
                    }),

                Tables\Actions\EditAction::make()
                    ->visible(fn (Purchase $record): bool => ! $record->isReceived()),

                Tables\Actions\DeleteAction::make()
                    ->visible(fn (Purchase $record): bool => ! $record->isReceived()),
            ])
            ->bulkActions([
                Tables\Actions\BulkActionGroup::make([
                    Tables\Actions\DeleteBulkAction::make(),
                ]),
            ])
            ->emptyStateHeading('Sin compras')
            ->emptyStateDescription('Crea una compra en borrador y luego márcala como recibida para aumentar el inventario.');
    }

    public static function getRelations(): array
    {
        return [];
    }

    public static function getPages(): array
    {
        return [
            'index' => Pages\ListPurchases::route('/'),
            'create' => Pages\CreatePurchase::route('/create'),
            'edit' => Pages\EditPurchase::route('/{record}/edit'),
        ];
    }

    public static function getEloquentQuery(): Builder
    {
        return parent::getEloquentQuery()
            ->withoutGlobalScopes([
                SoftDeletingScope::class,
            ]);
    }

    // ==================== CÁLCULOS ====================

    protected static function tenantIsPharmacy(): bool
    {
        $tenant = filament()->getTenant();

        return $tenant && method_exists($tenant, 'isPharmacy') && $tenant->isPharmacy();
    }

    public static function calculateItemSubtotal(Get|callable $get, Set|callable $set): void
    {
        $cost = (float) ($get('unit_cost') ?? 0);
        $quantity = (float) ($get('quantity') ?? 1);
        $set('subtotal', number_format($cost * $quantity, 2, '.', ''));
    }

    public static function calculateTotals(Get|callable $get, Set|callable $set): void
    {
        $items = $get('items') ?? [];
        $subtotal = 0.0;

        if (is_array($items)) {
            foreach ($items as $item) {
                if (! is_array($item)) {
                    continue;
                }
                $subtotal += (float) ($item['subtotal'] ?? 0);
            }
        }

        $tax = round($subtotal * 0.18, 2);
        $total = round($subtotal + $tax, 2);

        $set('subtotal', number_format($subtotal, 2, '.', ''));
        $set('tax', number_format($tax, 2, '.', ''));
        $set('total', number_format($total, 2, '.', ''));
    }

    public static function canViewAny(): bool
    {
        if (! static::tenantHasModule()) {
            return false;
        }

        return auth()->user()?->canPerform('view_purchases') ?? false;
    }

    public static function canCreate(): bool
    {
        if (! static::tenantHasModule()) {
            return false;
        }

        // Tabla: supervisor, admin, owner
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
