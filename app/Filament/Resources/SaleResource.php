<?php

namespace App\Filament\Resources;

use App\Enums\PaymentMethod;
use App\Enums\SaleStatus;
use App\Filament\Concerns\ChecksBusinessModule;
use App\Filament\Resources\SaleResource\Pages;
use App\Models\Customer;
// ElectronicInvoiceResource used for e-CF view link
use App\Models\Product;
use App\Models\Sale;
use App\Services\SaleService;
use Filament\Facades\Filament;
use Filament\Forms;
use Filament\Forms\Form;
use Filament\Forms\Get;
use Filament\Forms\Set;
use Filament\Infolists;
use Filament\Infolists\Infolist;
use Filament\Notifications\Notification;
use Filament\Resources\Resource;
use Filament\Tables;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\SoftDeletingScope;
use Illuminate\Support\HtmlString;

class SaleResource extends Resource
{
    use ChecksBusinessModule;

    protected static string $businessModule = 'sales';

    protected static string $viewPermission = 'view_sales';
    protected static string $createPermission = 'create_sales';
    protected static string $editPermission = 'edit_sales';
    protected static ?string $deletePermission = null;

    /** Visible en menú del cajero */
    protected static bool $cashierNavigation = true;

    protected static ?string $model = Sale::class;

    protected static ?string $navigationIcon = 'heroicon-o-shopping-cart';

    protected static ?string $navigationLabel = 'Ventas';

    protected static ?string $modelLabel = 'Venta';

    protected static ?string $pluralModelLabel = 'Ventas';

    protected static ?string $navigationGroup = 'Ventas';

    protected static ?int $navigationSort = 2;

    protected static ?string $recordTitleAttribute = 'sale_number';

    protected static ?string $tenantOwnershipRelationshipName = 'business';

    public static function getNavigationLabel(): string
    {
        return match (filament()->getTenant()?->business_type) {
            'cafeteria' => 'Ventas / Cuentas',
            'colmado' => 'Caja / Ventas',
            default => 'Ventas (POS)',
        };
    }

    public static function getModelLabel(): string
    {
        return match (filament()->getTenant()?->business_type) {
            'cafeteria' => 'Cuenta / Venta',
            default => 'Venta',
        };
    }

    public static function getNavigationGroup(): ?string
    {
        return 'Ventas';
    }

    public static function form(Form $form): Form
    {
        return $form
            ->schema([
                Forms\Components\Section::make('Cliente y pago')
                    ->schema([
                        Forms\Components\TextInput::make('sale_number')
                            ->label('Nº Venta')
                            ->disabled()
                            ->dehydrated()
                            ->placeholder('Se genera al guardar (VTA-2026-00001)'),

                        Forms\Components\TextInput::make('customer_name')
                            ->label('Cliente (nombre)')
                            ->placeholder('Cliente general')
                            ->maxLength(255),

                        Forms\Components\Select::make('customer_id')
                            ->label(function (): string {
                                return match (filament()->getTenant()?->business_type) {
                                    'colmado' => 'Cliente (fiado)',
                                    'distribuidora', 'almacen' => 'Cliente mayoreo',
                                    default => 'Cliente',
                                };
                            })
                            ->options(fn () => Customer::query()
                                ->orderBy('name')
                                ->limit(200)
                                ->pluck('name', 'id')
                                ->all())
                            ->searchable()
                            ->preload()
                            ->nullable()
                            ->live()
                            ->required(function (Get $get): bool {
                                $type = filament()->getTenant()?->business_type;
                                if (in_array($type, ['distribuidora', 'almacen'], true)) {
                                    return true;
                                }

                                return PaymentMethod::isCreditValue($get('payment_method'));
                            })
                            ->helperText(function (): ?string {
                                return match (filament()->getTenant()?->business_type) {
                                    'colmado' => 'Obligatorio si es fiado',
                                    'farmacia' => 'Opcional — para historial del cliente',
                                    'distribuidora', 'almacen' => 'Obligatorio en ventas de mayoreo',
                                    default => 'Opcional (obligatorio si es crédito / fiado)',
                                };
                            })
                            ->afterStateUpdated(function (?string $state, Set $set): void {
                                if ($state) {
                                    $c = Customer::query()->find($state);
                                    if ($c) {
                                        $set('customer_name', $c->name);
                                    }
                                }
                            }),

                        Forms\Components\Select::make('payment_method')
                            ->label('Método de pago')
                            ->options(function (): array {
                                $type = filament()->getTenant()?->type
                                    ?? \App\Enums\BusinessType::tryFrom(
                                        (string) (filament()->getTenant()?->business_type ?? 'general')
                                    );

                                return PaymentMethod::optionsForBusinessType(
                                    $type instanceof \App\Enums\BusinessType ? $type : null
                                );
                            })
                            ->default(PaymentMethod::Efectivo->value)
                            ->required()
                            ->native(false)
                            ->live()
                            ->afterStateUpdated(function (?string $state, Get $get, Set $set): void {
                                if (PaymentMethod::isCreditValue($state)) {
                                    $set('amount_paid', 0);
                                    $set('change', 0);
                                }
                            }),

                        Forms\Components\TextInput::make('amount_paid')
                            ->label('Monto recibido')
                            ->numeric()
                            ->prefix('RD$')
                            ->default(0)
                            ->live(onBlur: true)
                            ->afterStateUpdated(function ($state, Get $get, Set $set): void {
                                $total = (float) ($get('total') ?? 0);
                                $set('change', max(0, round((float) $state - $total, 2)));
                            })
                            ->visible(fn (Get $get): bool => ! PaymentMethod::isCreditValue($get('payment_method'))),

                        Forms\Components\TextInput::make('change')
                            ->label('Cambio')
                            ->numeric()
                            ->prefix('RD$')
                            ->disabled()
                            ->dehydrated()
                            ->default(0)
                            ->visible(fn (Get $get): bool => ! PaymentMethod::isCreditValue($get('payment_method'))),

                        Forms\Components\TextInput::make('discount')
                            ->label('Descuento')
                            ->numeric()
                            ->prefix('RD$')
                            ->default(0)
                            ->live(onBlur: true)
                            ->afterStateUpdated(fn (Get $get, Set $set) => self::calculateTotals($get, $set)),

                        Forms\Components\Textarea::make('notes')
                            ->label(function (): string {
                                return match (filament()->getTenant()?->business_type) {
                                    'ferreteria' => 'Notas / especificaciones',
                                    'cafeteria' => 'Notas / modificadores',
                                    default => 'Notas',
                                };
                            })
                            ->placeholder(function (): ?string {
                                return match (filament()->getTenant()?->business_type) {
                                    'ferreteria' => 'Ej: 1/2 pulgada, color, entrega…',
                                    'cafeteria' => 'Ej: sin cebolla, extra queso…',
                                    default => null,
                                };
                            })
                            ->rows(2)
                            ->columnSpanFull(),
                    ])
                    ->columns(3),

                Forms\Components\Section::make(function (): string {
                    return match (filament()->getTenant()?->business_type) {
                        'farmacia' => 'Búsqueda de medicamento',
                        'ferreteria' => 'Código / SKU',
                        default => 'Escáner de Código de Barras',
                    };
                })
                    ->schema([
                        Forms\Components\TextInput::make('barcode_scanner')
                            ->label(function (): string {
                                return match (filament()->getTenant()?->business_type) {
                                    'farmacia' => 'Buscar medicamento / código',
                                    'ferreteria' => 'Código / SKU',
                                    default => 'Escanear código de barras',
                                };
                            })
                            ->placeholder(function (): string {
                                return match (filament()->getTenant()?->business_type) {
                                    'farmacia' => 'Nombre, principio activo o código…',
                                    'colmado' => 'Escanea o escribe el código…',
                                    'ferreteria' => 'Código, SKU o escribe y Enter…',
                                    default => 'Código de barras o nombre…',
                                };
                            })
                            ->helperText(function (): string {
                                return match (filament()->getTenant()?->business_type) {
                                    'farmacia' => 'Busca por código exacto (barcode/SKU). Revisa stock y vencimientos en el producto.',
                                    'colmado' => 'Escáner primero: el producto se agrega al presionar Enter.',
                                    default => 'El producto se agrega solo. Revisa el recuadro de confirmación.',
                                };
                            })
                            ->dehydrated(false)
                            ->live(debounce: 300)
                            ->extraInputAttributes([
                                'autocomplete' => 'off',
                                'autocapitalize' => 'off',
                                'autofocus' => true,
                            ])
                            ->afterStateUpdated(function ($state, Set $set, Get $get): void {
                                $code = trim((string) ($state ?? ''));
                                if ($code === '') {
                                    return;
                                }

                                $product = Product::query()
                                    ->where('is_active', true)
                                    ->where(function (Builder $q) use ($code): void {
                                        $q->where('barcode', $code)
                                            ->orWhere('sku', $code);
                                    })
                                    ->first();

                                if (! $product) {
                                    Notification::make()
                                        ->title('Producto no encontrado')
                                        ->body('No existe ningún producto activo con el código: '.$code)
                                        ->danger()
                                        ->duration(3000)
                                        ->send();

                                    $set('barcode_scanner', '');

                                    return;
                                }

                                $items = $get('items') ?? [];
                                if (! is_array($items)) {
                                    $items = [];
                                }

                                $found = false;
                                $qtyInLine = 1.0;

                                foreach ($items as $key => $item) {
                                    if (! is_array($item)) {
                                        continue;
                                    }
                                    if ((string) ($item['product_id'] ?? '') === (string) $product->id) {
                                        $newQty = (float) ($item['quantity'] ?? 1) + 1;
                                        $unitPrice = (float) ($item['unit_price'] ?? $product->price);
                                        $items[$key]['quantity'] = $newQty;
                                        $items[$key]['unit_price'] = $unitPrice;
                                        $items[$key]['subtotal'] = round($unitPrice * $newQty, 2);
                                        $items[$key]['product_name'] = $product->name;
                                        $found = true;
                                        $qtyInLine = $newQty;
                                        break;
                                    }
                                }

                                if (! $found) {
                                    $emptyKey = null;
                                    foreach ($items as $key => $item) {
                                        if (is_array($item) && empty($item['product_id'])) {
                                            $emptyKey = $key;
                                            break;
                                        }
                                    }

                                    $row = [
                                        'product_id' => $product->id,
                                        'product_name' => $product->name,
                                        'unit_price' => (float) $product->price,
                                        'quantity' => 1,
                                        'subtotal' => (float) $product->price,
                                    ];

                                    if ($emptyKey !== null) {
                                        $items[$emptyKey] = $row;
                                    } else {
                                        $items[] = $row;
                                    }
                                    $qtyInLine = 1;
                                }

                                $set('items', array_values($items));
                                $set('barcode_scanner', '');

                                // Área fija: último producto escaneado (visible hasta el siguiente scan)
                                $set('last_scanned_product', [
                                    'name' => $product->name,
                                    'price' => (float) $product->price,
                                    'quantity' => $qtyInLine,
                                ]);

                                self::calculateTotals($get, $set);

                                Notification::make()
                                    ->title('Producto agregado')
                                    ->body(new HtmlString(
                                        '<div style="font-size:16px;font-weight:700;">'.e($product->name).'</div>'
                                        .'<div style="font-size:13px;">RD$ '.number_format((float) $product->price, 2)
                                        .' · Cant. '.rtrim(rtrim(number_format((float) $qtyInLine, 3, '.', ''), '0'), '.').'</div>'
                                    ))
                                    ->success()
                                    ->duration(2000)
                                    ->send();
                            })
                            ->columnSpanFull(),

                        Forms\Components\Placeholder::make('last_scanned_product_display')
                            ->label('Último producto escaneado')
                            ->content(function (Get $get): HtmlString|string {
                                $last = $get('last_scanned_product');
                                if (is_string($last) && $last !== '') {
                                    $decoded = json_decode($last, true);
                                    $last = is_array($decoded) ? $decoded : null;
                                }

                                if (! is_array($last) || empty($last['name'])) {
                                    return new HtmlString(
                                        '<div style="background:#f9fafb;border:1px dashed #d1d5db;padding:12px;border-radius:8px;color:#6b7280;font-size:14px;">'
                                        .'Ningún producto escaneado aún'
                                        .'</div>'
                                    );
                                }

                                $qty = rtrim(rtrim(number_format((float) ($last['quantity'] ?? 1), 3, '.', ''), '0'), '.');

                                return new HtmlString(
                                    '<div style="background-color:#f0fdf4;border:2px solid #166534;padding:12px;border-radius:8px;">'
                                    .'<div style="font-size:18px;font-weight:bold;color:#166534;line-height:1.3;">'
                                    .e((string) $last['name'])
                                    .'</div>'
                                    .'<div style="font-size:14px;color:#4b5563;margin-top:4px;">'
                                    .'Precio: <strong>RD$ '.number_format((float) ($last['price'] ?? 0), 2).'</strong>'
                                    .' &nbsp;×&nbsp; Cantidad en línea: <strong>'.$qty.'</strong>'
                                    .'</div>'
                                    .'</div>'
                                );
                            })
                            ->columnSpanFull(),

                        Forms\Components\Hidden::make('last_scanned_product')
                            ->dehydrated(false)
                            ->default(null),
                    ])
                    ->collapsible()
                    ->collapsed(false),

                Forms\Components\Section::make('Productos')
                    ->schema([
                        Forms\Components\Repeater::make('items')
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
                                    ->afterStateUpdated(function ($state, Set $set, Get $get): void {
                                        if ($state) {
                                            $product = Product::query()->find($state);
                                            if ($product) {
                                                $set('product_name', $product->name);
                                                $set('unit_price', $product->price);
                                                $set('quantity', 1);
                                                $set('subtotal', $product->price);
                                            }
                                        }
                                        self::calculateTotals($get, $set, fromItem: true);
                                    })
                                    ->columnSpan(3),

                                Forms\Components\Hidden::make('product_name'),

                                Forms\Components\TextInput::make('unit_price')
                                    ->label('Precio')
                                    ->numeric()
                                    ->prefix('RD$')
                                    ->required()
                                    ->live(onBlur: true)
                                    ->afterStateUpdated(function ($state, Get $get, Set $set): void {
                                        $qty = (float) ($get('quantity') ?? 1);
                                        $set('subtotal', round((float) $state * $qty, 2));
                                        self::calculateTotals($get, $set, fromItem: true);
                                    })
                                    ->columnSpan(2),

                                Forms\Components\TextInput::make('quantity')
                                    ->label('Cant.')
                                    ->numeric()
                                    ->default(1)
                                    ->minValue(0.001)
                                    ->required()
                                    ->live(onBlur: true)
                                    ->afterStateUpdated(function ($state, Get $get, Set $set): void {
                                        $price = (float) ($get('unit_price') ?? 0);
                                        $set('subtotal', round($price * (float) $state, 2));
                                        self::calculateTotals($get, $set, fromItem: true);
                                    })
                                    ->columnSpan(2),

                                Forms\Components\TextInput::make('subtotal')
                                    ->label('Subtotal')
                                    ->numeric()
                                    ->prefix('RD$')
                                    ->disabled()
                                    ->dehydrated()
                                    ->columnSpan(2),
                            ])
                            ->columns(9)
                            ->defaultItems(1)
                            ->addActionLabel('Agregar producto')
                            ->reorderable(false)
                            ->collapsible()
                            ->itemLabel(fn (array $state): ?string => $state['product_name'] ?? null)
                            ->columnSpanFull()
                            ->live()
                            ->afterStateUpdated(fn (Get $get, Set $set) => self::calculateTotals($get, $set))
                            ->deleteAction(
                                fn (Forms\Components\Actions\Action $action) => $action->after(
                                    fn (Get $get, Set $set) => self::calculateTotals($get, $set)
                                )
                            ),
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
                        Forms\Components\TextInput::make('tax_rate')
                            ->label('ITBIS %')
                            ->numeric()
                            ->default(18)
                            ->disabled()
                            ->dehydrated(),
                        Forms\Components\TextInput::make('tax')
                            ->label('ITBIS')
                            ->numeric()
                            ->prefix('RD$')
                            ->disabled()
                            ->dehydrated()
                            ->default(0),
                        Forms\Components\TextInput::make('total')
                            ->label('Total a Pagar')
                            ->numeric()
                            ->prefix('RD$')
                            ->disabled()
                            ->dehydrated()
                            ->default(0)
                            ->extraAttributes(['class' => 'font-bold text-lg text-primary-600']),
                        Forms\Components\Hidden::make('status')->default(SaleStatus::Completada->value),
                    ])
                    ->columns(4),
            ]);
    }

    public static function calculateTotals(Get $get, Set $set, bool $fromItem = false): void
    {
        $prefix = $fromItem ? '../../' : '';
        $items = $get($prefix.'items') ?? [];
        $subtotal = 0.0;

        foreach ($items as $item) {
            if (! is_array($item)) {
                continue;
            }
            $line = $item['subtotal']
                ?? ((float) ($item['unit_price'] ?? 0) * (float) ($item['quantity'] ?? 0));
            $subtotal += (float) $line;
        }

        $discount = (float) ($get($prefix.'discount') ?? 0);
        $taxRate = (float) ($get($prefix.'tax_rate') ?? 18);
        $base = max(0, $subtotal - $discount);
        $tax = round($base * ($taxRate / 100), 2);
        $total = round($base + $tax, 2);
        $paid = (float) ($get($prefix.'amount_paid') ?? 0);

        $set($prefix.'subtotal', number_format($subtotal, 2, '.', ''));
        $set($prefix.'tax', number_format($tax, 2, '.', ''));
        $set($prefix.'total', number_format($total, 2, '.', ''));
        $set($prefix.'change', number_format(max(0, $paid - $total), 2, '.', ''));
    }

    public static function infolist(Infolist $infolist): Infolist
    {
        return $infolist
            ->schema([
                Infolists\Components\Section::make('Venta')
                    ->schema([
                        Infolists\Components\TextEntry::make('sale_number')
                            ->label('Número')
                            ->default(fn (Sale $r) => $r->sale_number ?: $r->number)
                            ->weight('bold'),
                        Infolists\Components\TextEntry::make('sale_date')
                            ->label('Fecha')
                            ->dateTime('d/m/Y H:i')
                            ->default(fn (Sale $r) => $r->sale_date ?: $r->sold_at),
                        Infolists\Components\TextEntry::make('status')
                            ->label('Estado')
                            ->badge()
                            ->formatStateUsing(fn ($state) => $state instanceof SaleStatus ? $state->label() : (string) $state)
                            ->color(fn ($state) => $state instanceof SaleStatus ? $state->color() : 'gray'),
                        Infolists\Components\TextEntry::make('payment_method')
                            ->label('Pago')
                            ->formatStateUsing(fn ($state) => $state instanceof PaymentMethod ? $state->label() : (string) $state),
                        Infolists\Components\TextEntry::make('customer_name')
                            ->label('Cliente')
                            ->placeholder(fn (Sale $r) => $r->customer?->name ?? 'Cliente general'),
                        Infolists\Components\TextEntry::make('creator.name')
                            ->label('Cajero')
                            ->placeholder(fn (Sale $r) => $r->cashier?->name ?? '—'),
                        Infolists\Components\TextEntry::make('notes')
                            ->label('Notas')
                            ->placeholder('—')
                            ->columnSpanFull(),
                    ])
                    ->columns(3),

                Infolists\Components\Section::make('Totales')
                    ->schema([
                        Infolists\Components\TextEntry::make('subtotal')->money('DOP'),
                        Infolists\Components\TextEntry::make('discount')->label('Descuento')->money('DOP'),
                        Infolists\Components\TextEntry::make('tax')->label('ITBIS')->money('DOP'),
                        Infolists\Components\TextEntry::make('total')->label('Total')->money('DOP')->weight('bold'),
                        Infolists\Components\TextEntry::make('amount_paid')->label('Pagado')->money('DOP'),
                        Infolists\Components\TextEntry::make('change')
                            ->label('Cambio')
                            ->money('DOP')
                            ->default(fn (Sale $r) => $r->change ?? $r->change_due),
                    ])
                    ->columns(3),

                Infolists\Components\RepeatableEntry::make('items')
                    ->label('Ítems')
                    ->schema([
                        Infolists\Components\TextEntry::make('product_name')->label('Producto'),
                        Infolists\Components\TextEntry::make('quantity')->label('Cant.'),
                        Infolists\Components\TextEntry::make('unit_price')->label('P. unit.')->money('DOP'),
                        Infolists\Components\TextEntry::make('subtotal')
                            ->label('Total')
                            ->money('DOP')
                            ->default(fn ($record) => $record->subtotal ?? $record->line_total),

                        // Trazabilidad: lotes despachados por esta línea (solo si hay allocations)
                        Infolists\Components\RepeatableEntry::make('batchAllocations')
                            ->label('Lotes despachados')
                            ->schema([
                                Infolists\Components\TextEntry::make('batch.code')
                                    ->label('Lote')
                                    ->badge()
                                    ->placeholder('—'),
                                Infolists\Components\TextEntry::make('batch.expires_at')
                                    ->label('Vence')
                                    ->date('d/m/Y'),
                                Infolists\Components\TextEntry::make('quantity')
                                    ->label('Cantidad')
                                    ->numeric(decimalPlaces: 3),
                                Infolists\Components\TextEntry::make('returned_quantity')
                                    ->label('Devuelto')
                                    ->numeric(decimalPlaces: 3)
                                    ->color(fn ($state) => (float) $state > 0 ? 'warning' : 'gray'),
                            ])
                            ->columns(4)
                            ->columnSpanFull()
                            ->visible(fn ($record) => $record->batchAllocations()->exists()),
                    ])
                    ->columns(4),
            ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                Tables\Columns\TextColumn::make('sale_number')
                    ->label('Número')
                    ->default(fn (Sale $r) => $r->sale_number ?: $r->number)
                    ->searchable(query: fn (Builder $q, string $s) => $q
                        ->where('sale_number', 'like', "%{$s}%")
                        ->orWhere('number', 'like', "%{$s}%"))
                    ->sortable()
                    ->weight('bold')
                    ->copyable(),

                Tables\Columns\TextColumn::make('customer_name')
                    ->label('Cliente')
                    ->default(fn (Sale $r) => $r->customer_name ?: $r->customer?->name)
                    ->placeholder('General')
                    ->searchable(),

                Tables\Columns\TextColumn::make('total')
                    ->label('Total')
                    ->money('DOP')
                    ->sortable()
                    ->weight('bold'),

                Tables\Columns\TextColumn::make('payment_method')
                    ->label('Pago')
                    ->badge()
                    ->formatStateUsing(fn ($state) => $state instanceof PaymentMethod ? $state->label() : (string) $state),

                Tables\Columns\TextColumn::make('status')
                    ->label('Estado')
                    ->badge()
                    ->formatStateUsing(fn ($state) => $state instanceof SaleStatus ? $state->label() : (string) $state)
                    ->color(fn ($state) => $state instanceof SaleStatus ? $state->color() : 'gray'),

                Tables\Columns\TextColumn::make('sale_date')
                    ->label('Fecha')
                    ->dateTime('d/m/Y H:i')
                    ->default(fn (Sale $r) => $r->sale_date ?: $r->sold_at)
                    ->sortable(),

                Tables\Columns\TextColumn::make('creator.name')
                    ->label('Cajero')
                    ->toggleable(isToggledHiddenByDefault: true),

                \App\Filament\Support\BranchTableHelpers::column(),
            ])
            ->defaultSort('created_at', 'desc')
            ->filters([
                Tables\Filters\SelectFilter::make('status')
                    ->options(SaleStatus::options()),
                Tables\Filters\SelectFilter::make('payment_method')
                    ->label('Pago')
                    ->options(PaymentMethod::options()),
                \App\Filament\Support\BranchTableHelpers::filter(),
                Tables\Filters\TrashedFilter::make(),
            ])
            ->actions([
                Tables\Actions\Action::make('cobrar')
                    ->label('Cobrar')
                    ->icon('heroicon-o-banknotes')
                    ->color('success')
                    ->visible(fn (Sale $record): bool => ! static::saleIsCompleted($record))
                    ->modalHeading(fn (Sale $record): string => 'Cobrar venta #'.($record->sale_number ?? $record->id))
                    ->modalSubmitActionLabel('Confirmar e imprimir')
                    ->form(fn (Sale $record): array => static::getCobrarFormSchema($record))
                    ->action(fn (Sale $record, array $data) => static::processCobro($record, $data)),

                Tables\Actions\Action::make('imprimir_ticket')
                    ->label('Imprimir')
                    ->icon('heroicon-o-printer')
                    ->color('gray')
                    ->visible(fn (Sale $record): bool => static::saleIsCompleted($record))
                    ->url(fn (Sale $record): string => static::getUrl('ticket', ['record' => $record]))
                    ->openUrlInNewTab(),

                Tables\Actions\Action::make('emitir_ecf')
                    ->label('Emitir e-CF')
                    ->icon('heroicon-o-document-text')
                    ->color('primary')
                    ->visible(function (Sale $record): bool {
                        if (! \App\Support\EcfFeature::enabled()) {
                            return false;
                        }

                        $tenant = filament()->getTenant();
                        if (! $tenant || ! $tenant->hasModule('electronic_invoicing')) {
                            return false;
                        }

                        $statusOk = $record->status?->isCompleted()
                            ?? in_array((string) $record->status, ['completada', 'completed'], true);

                        if (! $statusOk) {
                            return false;
                        }

                        return ! $record->electronicInvoice()->exists();
                    })
                    ->form([
                        Forms\Components\Select::make('ecf_type')
                            ->label('Tipo de Comprobante')
                            ->options([
                                '32' => '32 - Factura de Consumo',
                                '31' => '31 - Factura de Crédito Fiscal',
                            ])
                            ->default('32')
                            ->required()
                            ->native(false)
                            ->helperText('Consumo = cliente final. Crédito Fiscal = con RNC (empresas).'),
                    ])
                    ->requiresConfirmation()
                    ->modalHeading('Emitir Comprobante Electrónico')
                    ->modalDescription('Se generará el número fiscal y el comprobante interno. El envío a la DGII se implementará después.')
                    ->action(function (Sale $record, array $data): void {
                        try {
                            $type = \App\Enums\EcfType::from((string) $data['ecf_type']);
                            $invoice = \App\Services\EcfService::createFromSale($record, $type);

                            Notification::make()
                                ->title('Comprobante emitido')
                                ->body('e-CF: '.$invoice->ecf_number)
                                ->success()
                                ->actions([
                                    \Filament\Notifications\Actions\Action::make('ver')
                                        ->label('Ver comprobante')
                                        ->url(\App\Filament\Resources\ElectronicInvoiceResource::getUrl('view', ['record' => $invoice])),
                                ])
                                ->send();
                        } catch (\Throwable $e) {
                            Notification::make()
                                ->title('No se pudo emitir el e-CF')
                                ->body($e->getMessage())
                                ->danger()
                                ->persistent()
                                ->send();
                        }
                    }),

                Tables\Actions\ViewAction::make(),

                Tables\Actions\Action::make('anular')
                    ->label('Anular')
                    ->icon('heroicon-o-x-circle')
                    ->color('danger')
                    ->requiresConfirmation()
                    ->visible(fn (Sale $record) => $record->isCancellable())
                    ->action(function (Sale $record): void {
                        try {
                            app(SaleService::class)->cancel($record);
                            Notification::make()->title('Venta anulada')->success()->send();
                        } catch (\Throwable $e) {
                            Notification::make()->title('No se pudo anular')->body($e->getMessage())->danger()->send();
                        }
                    }),
            ])
            ->bulkActions([])
            ->emptyStateHeading('Sin ventas')
            ->emptyStateDescription('Registra la primera venta del negocio (POS).')
            ->emptyStateIcon('heroicon-o-shopping-cart');
    }

    public static function getRelations(): array
    {
        return [];
    }

    public static function getPages(): array
    {
        return [
            'index' => Pages\ListSales::route('/'),
            'create' => Pages\CreateSale::route('/create'),
            'view' => Pages\ViewSale::route('/{record}'),
            'ticket' => Pages\PrintTicket::route('/{record}/ticket'),
        ];
    }

    /**
     * ¿La venta ya está cobrada / completada?
     */
    public static function saleIsCompleted(Sale $record): bool
    {
        if ($record->status instanceof SaleStatus) {
            return $record->status->isCompleted();
        }

        return in_array((string) $record->getRawOriginal('status'), ['completada', 'completed'], true);
    }

    /**
     * Formulario del modal Cobrar (lista, CreateSale, reutilizable).
     *
     * @return array<\Filament\Forms\Components\Component>
     */
    public static function getCobrarFormSchema(Sale $record): array
    {
        $type = filament()->getTenant()?->business_type ?? 'general';
        $typeEnum = \App\Enums\BusinessType::tryFrom($type);
        $total = (float) ($record->total ?? 0);

        return [
            Forms\Components\Placeholder::make('total_display')
                ->label('Total a cobrar')
                ->content(new HtmlString(
                    '<div class="text-2xl font-bold text-primary-600">RD$ '
                    .number_format($total, 2)
                    .'</div>'
                )),

            Forms\Components\Select::make('payment_method')
                ->label('Método de pago')
                ->options(PaymentMethod::optionsForBusinessType($typeEnum))
                ->required()
                ->default(PaymentMethod::Efectivo->value)
                ->native(false)
                ->live(),

            Forms\Components\Select::make('customer_id')
                ->label(match ($type) {
                    'colmado' => 'Cliente (fiado)',
                    'distribuidora', 'almacen' => 'Cliente mayoreo',
                    default => 'Cliente',
                })
                ->options(fn () => Customer::query()->orderBy('name')->limit(150)->pluck('name', 'id'))
                ->searchable()
                ->preload()
                ->default($record->customer_id)
                ->visible(fn (Get $get): bool => PaymentMethod::isCreditValue($get('payment_method')))
                ->required(fn (Get $get): bool => PaymentMethod::isCreditValue($get('payment_method')))
                ->helperText('Obligatorio para fiado / crédito'),

            Forms\Components\TextInput::make('amount_tendered')
                ->label('¿Con cuánto paga?')
                ->numeric()
                ->prefix('RD$')
                ->default($total > 0 ? number_format($total, 2, '.', '') : null)
                ->required(fn (Get $get): bool => ($get('payment_method') ?? '') === PaymentMethod::Efectivo->value)
                ->visible(fn (Get $get): bool => ($get('payment_method') ?? '') === PaymentMethod::Efectivo->value)
                ->live(debounce: 300)
                ->helperText('Monto que entrega el cliente')
                ->extraInputAttributes(['inputmode' => 'decimal', 'autofocus' => true]),

            Forms\Components\Placeholder::make('change_display')
                ->label('Vuelto')
                ->content(function (Get $get) use ($total): HtmlString|string {
                    if (($get('payment_method') ?? '') !== PaymentMethod::Efectivo->value) {
                        return '—';
                    }

                    $tendered = (float) ($get('amount_tendered') ?? 0);
                    if ($tendered <= 0) {
                        return new HtmlString('<span class="text-gray-500">Ingresa el monto entregado</span>');
                    }

                    $change = round($tendered - $total, 2);
                    if ($change < 0) {
                        return new HtmlString(
                            '<span class="text-danger-600 font-semibold">⚠ Faltan RD$ '
                            .number_format(abs($change), 2).'</span>'
                        );
                    }

                    return new HtmlString(
                        '<span class="text-success-600 text-xl font-bold">RD$ '
                        .number_format($change, 2).'</span>'
                    );
                })
                ->visible(fn (Get $get): bool => ($get('payment_method') ?? '') === PaymentMethod::Efectivo->value),
        ];
    }

    /**
     * Procesa el cobro de una venta existente y redirige al ticket.
     *
     * @param  array<string, mixed>  $data
     * @return \Illuminate\Http\RedirectResponse|null
     */
    public static function processCobro(Sale $record, array $data)
    {
        $method = PaymentMethod::normalize((string) ($data['payment_method'] ?? 'efectivo'));
        $tendered = (float) ($data['amount_tendered'] ?? 0);
        $total = (float) $record->total;

        if ($method === PaymentMethod::Efectivo && $tendered < $total) {
            Notification::make()
                ->title('Monto insuficiente')
                ->body('Debe entregar al menos RD$ '.number_format($total, 2))
                ->danger()
                ->send();

            return null;
        }

        if ($method->isCredit() && empty($data['customer_id'])) {
            Notification::make()
                ->title('Cliente requerido')
                ->body('Para fiado / crédito debe elegir un cliente')
                ->danger()
                ->send();

            return null;
        }

        $change = $method === PaymentMethod::Efectivo
            ? max(0, round($tendered - $total, 2))
            : 0.0;

        $amountPaid = $method->isCredit()
            ? 0.0
            : ($method === PaymentMethod::Efectivo ? $tendered : $total);

        $update = [
            'payment_method' => $method,
            'status' => SaleStatus::Completada,
            'amount_paid' => $amountPaid,
            'change' => $change,
            'change_due' => $change,
            'amount_tendered' => $method === PaymentMethod::Efectivo ? $tendered : null,
            'change_amount' => $method === PaymentMethod::Efectivo ? $change : null,
        ];

        if (! empty($data['customer_id'])) {
            $update['customer_id'] = $data['customer_id'];
            $name = Customer::query()->whereKey($data['customer_id'])->value('name');
            if ($name) {
                $update['customer_name'] = $name;
            }
        }

        $record->update($update);

        if ($method === PaymentMethod::Efectivo && $change > 0) {
            Notification::make()
                ->title('Vuelto: RD$ '.number_format($change, 2))
                ->success()
                ->duration(8000)
                ->send();
        }

        Notification::make()
            ->title('Venta cobrada')
            ->body('Total: RD$ '.number_format($total, 2))
            ->success()
            ->send();

        return redirect()->to(static::getUrl('ticket', ['record' => $record]));
    }

    public static function getEloquentQuery(): Builder
    {
        return parent::getEloquentQuery()
            ->withoutGlobalScopes([SoftDeletingScope::class]);
    }

    public static function canViewAny(): bool
    {
        if (! static::tenantHasModule()) {
            return false;
        }

        return auth()->user()?->canPerform('view_sales') ?? false;
    }

    public static function canCreate(): bool
    {
        if (! static::tenantHasModule()) {
            return false;
        }

        return auth()->user()?->canPerform('create_sales') ?? false;
    }

    public static function canEdit($record): bool
    {
        if (! static::tenantHasModule()) {
            return false;
        }

        return auth()->user()?->canPerform('edit_sales') ?? false;
    }

    public static function canDelete($record): bool
    {
        if (! static::tenantHasModule()) {
            return false;
        }

        return auth()->user()?->hasRoleInBusiness(['owner', 'admin']) ?? false;
    }

}
