<?php

namespace App\Filament\Resources;

use App\Enums\OrderStatus;
use App\Enums\OrderType;
use App\Filament\Concerns\ChecksBusinessModule;
use App\Filament\Resources\OrderResource\Pages;
use App\Models\Order;
use App\Models\Product;
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
use Illuminate\Support\HtmlString;

class OrderResource extends Resource
{
    use ChecksBusinessModule;

    protected static string $businessModule = 'orders';

    protected static string $viewPermission = 'view_orders';
    protected static string $createPermission = 'create_orders';
    protected static string $editPermission = 'edit_orders';
    protected static ?string $deletePermission = null;

    protected static ?string $model = Order::class;

    protected static ?string $navigationIcon = 'heroicon-o-clipboard-document-list';

    protected static ?string $navigationLabel = 'Órdenes';

    protected static ?string $modelLabel = 'Orden';

    protected static ?string $pluralModelLabel = 'Órdenes';

    protected static ?string $navigationGroup = 'Ventas';

    protected static ?int $navigationSort = 3;

    public static function getNavigationLabel(): string
    {
        return match (filament()->getTenant()?->business_type) {
            'cafeteria' => 'Órdenes',
            'ferreteria' => 'Cotizaciones / Órdenes',
            'repuestos' => 'Cotizaciones',
            'distribuidora', 'almacen' => 'Pedidos / Órdenes',
            default => 'Órdenes',
        };
    }

    public static function getNavigationGroup(): ?string
    {
        return 'Ventas';
    }

    protected static ?string $recordTitleAttribute = 'order_number';

    /** Ownership multi-tenant (Product/Sale/Order → business) */
    protected static ?string $tenantOwnershipRelationshipName = 'business';

    public static function form(Form $form): Form
    {
        return $form
            ->schema([
                Forms\Components\Section::make('Tipo de Orden')
                    ->schema([
                        Forms\Components\Radio::make('order_type')
                            ->label('¿Cómo quieres identificar la orden?')
                            ->options([
                                'por_numero' => 'Por Número de Orden (automático)',
                                'por_nombre' => 'Por Nombre del Cliente',
                            ])
                            ->default('por_numero')
                            ->required()
                            ->live()
                            ->columnSpanFull()
                            ->descriptions([
                                'por_numero' => 'Se genera automáticamente (ej: ORD-2026-00001)',
                                'por_nombre' => 'Ideal para delivery o clientes frecuentes',
                            ]),

                        Forms\Components\TextInput::make('customer_name')
                            ->label('Nombre del Cliente')
                            ->placeholder('Ej: Juan Pérez')
                            ->required(fn (Get $get): bool => $get('order_type') === 'por_nombre')
                            ->visible(fn (Get $get): bool => $get('order_type') === 'por_nombre')
                            ->maxLength(255)
                            ->columnSpanFull(),

                        Forms\Components\TextInput::make('order_number')
                            ->label('Número de Orden')
                            ->disabled()
                            ->dehydrated()
                            ->visible(fn (Get $get): bool => $get('order_type') === 'por_numero')
                            ->helperText('Se genera automáticamente al guardar')
                            ->placeholder('ORD-2026-00001')
                            ->columnSpanFull(),
                    ])
                    ->columns(2),

                Forms\Components\Section::make('Escáner de Código de Barras')
                    ->schema([
                        Forms\Components\TextInput::make('barcode_scanner')
                            ->label('Escanea el código de barras')
                            ->placeholder('Escanea aquí el código del producto')
                            ->helperText('El producto se agrega solo a la orden. Confirma el nombre en la notificación verde.')
                            ->dehydrated(false)
                            ->live(debounce: 300)
                            ->extraInputAttributes([
                                'autocomplete' => 'off',
                                'autocapitalize' => 'off',
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
                                        $items[$emptyKey] = array_merge($items[$emptyKey] ?? [], $row);
                                    } else {
                                        $items[] = $row;
                                    }
                                    $qtyInLine = 1;
                                }

                                // No reindexar: el Repeater con relationship() usa keys de ítems
                                $set('items', $items);
                                $set('barcode_scanner', '');

                                // Área fija: último producto escaneado
                                $set('last_scanned_product', [
                                    'name' => $product->name,
                                    'price' => (float) $product->price,
                                    'quantity' => $qtyInLine,
                                ]);

                                self::calculateTotals($get, $set, fromItem: false);

                                Notification::make()
                                    ->title('Producto agregado a la orden')
                                    ->body(new HtmlString(
                                        '<div style="font-size:16px;font-weight:700;color:#166534;">'
                                        .e($product->name)
                                        .'</div>'
                                        .'<div style="font-size:13px;color:#4b5563;">'
                                        .'Precio: <strong>RD$ '.number_format((float) $product->price, 2).'</strong>'
                                        .' &nbsp;·&nbsp; Cantidad: <strong>'
                                        .rtrim(rtrim(number_format((float) $qtyInLine, 3, '.', ''), '0'), '.')
                                        .'</strong>'
                                        .'</div>'
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
                                    '<div style="background-color:#ecfdf5;border:2px solid #166534;padding:12px;border-radius:8px;">'
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

                Forms\Components\Section::make('Productos de la Orden')
                    ->schema([
                        Forms\Components\Repeater::make('items')
                            ->relationship()
                            ->schema([
                                Forms\Components\Select::make('product_id')
                                    ->label('Producto')
                                    ->options(function () {
                                        // Global Scope filtra por tenant actual
                                        return Product::query()
                                            ->where('is_active', true)
                                            ->orderBy('name')
                                            ->pluck('name', 'id');
                                    })
                                    ->searchable()
                                    ->required()
                                    ->reactive()
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

                                        // Recalcular totales de la orden
                                        self::calculateTotals($get, $set, fromItem: true);
                                    })
                                    ->columnSpan(3),

                                Forms\Components\TextInput::make('product_name')
                                    ->label('Nombre')
                                    ->disabled()
                                    ->dehydrated()
                                    ->columnSpan(2),

                                Forms\Components\TextInput::make('unit_price')
                                    ->label('Precio')
                                    ->numeric()
                                    ->prefix('RD$')
                                    ->required()
                                    ->live(onBlur: true)
                                    ->afterStateUpdated(function ($state, Get $get, Set $set): void {
                                        $quantity = floatval($get('quantity') ?? 1);
                                        $set('subtotal', floatval($state ?? 0) * $quantity);

                                        // Recalcular totales de la orden
                                        self::calculateTotals($get, $set, fromItem: true);
                                    })
                                    ->columnSpan(2),

                                Forms\Components\TextInput::make('quantity')
                                    ->label('Cantidad')
                                    ->numeric()
                                    ->default(1)
                                    ->minValue(1)
                                    ->required()
                                    ->live(onBlur: true)
                                    ->afterStateUpdated(function ($state, Get $get, Set $set): void {
                                        $price = floatval($get('unit_price') ?? 0);
                                        $quantity = floatval($state ?? 1);
                                        $set('subtotal', $price * $quantity);

                                        // Recalcular totales de la orden
                                        self::calculateTotals($get, $set, fromItem: true);
                                    })
                                    ->columnSpan(1),

                                Forms\Components\TextInput::make('subtotal')
                                    ->label('Subtotal')
                                    ->numeric()
                                    ->prefix('RD$')
                                    ->disabled()
                                    ->dehydrated()
                                    ->columnSpan(2),
                            ])
                            ->columns(10)
                            ->defaultItems(1)
                            ->addActionLabel('Agregar producto')
                            ->reorderable(false)
                            ->collapsible()
                            ->itemLabel(fn (array $state): ?string => $state['product_name'] ?? null)
                            ->columnSpanFull()
                            ->live()
                            ->afterStateUpdated(function (Get $get, Set $set): void {
                                self::calculateTotals($get, $set, fromItem: false);
                            })
                            ->deleteAction(
                                fn (Forms\Components\Actions\Action $action) => $action
                                    ->after(function (Get $get, Set $set): void {
                                        self::calculateTotals($get, $set, fromItem: false);
                                    })
                            ),
                    ]),

                Forms\Components\Section::make('Totales y Estado')
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
                            ->label('Total a Pagar')
                            ->numeric()
                            ->prefix('RD$')
                            ->disabled()
                            ->dehydrated()
                            ->default(0)
                            ->extraAttributes(['class' => 'font-bold text-lg text-primary-600']),

                        Forms\Components\Select::make('status')
                            ->label('Estado')
                            ->options([
                                'pendiente' => 'Pendiente',
                                'en_preparacion' => 'En Preparación',
                                'lista' => 'Lista',
                                'completada' => 'Completada',
                                'cancelada' => 'Cancelada',
                            ])
                            ->default('pendiente')
                            ->required(),

                        Forms\Components\Textarea::make('notes')
                            ->label('Notas / Observaciones')
                            ->rows(2)
                            ->columnSpanFull(),
                    ])
                    ->columns(4),
            ]);
    }

    // ==================== CÁLCULO AUTOMÁTICO DE TOTALES ====================

    /**
     * Recalcula subtotal, ITBIS (18%) y total a partir de los ítems.
     *
     * @param  bool  $fromItem  true si se llama desde un campo dentro del Repeater
     *                          (usa rutas relativas ../../ hacia el formulario raíz)
     */
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
                ?? (floatval($item['unit_price'] ?? 0) * floatval($item['quantity'] ?? 0));

            $subtotal += floatval($line);
        }

        $tax = round($subtotal * 0.18, 2); // ITBIS 18%
        $total = round($subtotal + $tax, 2);

        $set($prefix.'subtotal', number_format($subtotal, 2, '.', ''));
        $set($prefix.'tax', number_format($tax, 2, '.', ''));
        $set($prefix.'total', number_format($total, 2, '.', ''));
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                Tables\Columns\TextColumn::make('order_number')
                    ->label('Número')
                    ->searchable()
                    ->sortable()
                    ->weight('bold')
                    ->copyable(),

                Tables\Columns\TextColumn::make('order_type')
                    ->label('Tipo')
                    ->badge()
                    ->formatStateUsing(function ($state): string {
                        $value = $state instanceof OrderType ? $state->value : (string) $state;

                        return match ($value) {
                            'por_nombre' => 'Por Nombre',
                            'por_numero' => 'Por Número',
                            default => $value,
                        };
                    })
                    ->color(function ($state): string {
                        $value = $state instanceof OrderType ? $state->value : (string) $state;

                        return match ($value) {
                            'por_nombre' => 'info',
                            'por_numero' => 'success',
                            default => 'gray',
                        };
                    }),

                Tables\Columns\TextColumn::make('customer_name')
                    ->label('Cliente')
                    ->searchable()
                    ->placeholder('—')
                    ->toggleable(),

                Tables\Columns\TextColumn::make('status')
                    ->label('Estado')
                    ->badge()
                    ->color(function ($state): string {
                        $value = $state instanceof OrderStatus ? $state->value : (string) $state;

                        return match ($value) {
                            'pendiente' => 'warning',
                            'en_preparacion' => 'info',
                            'lista' => 'success',
                            'completada' => 'gray',
                            'cancelada' => 'danger',
                            default => 'gray',
                        };
                    })
                    ->formatStateUsing(function ($state): string {
                        $value = $state instanceof OrderStatus ? $state->value : (string) $state;

                        return match ($value) {
                            'pendiente' => 'Pendiente',
                            'en_preparacion' => 'En Preparación',
                            'lista' => 'Lista',
                            'completada' => 'Completada',
                            'cancelada' => 'Cancelada',
                            default => $value,
                        };
                    }),

                Tables\Columns\TextColumn::make('total')
                    ->label('Total')
                    ->money('DOP')
                    ->sortable(),

                Tables\Columns\TextColumn::make('created_at')
                    ->label('Creada')
                    ->dateTime('d/m/Y H:i')
                    ->sortable(),

                Tables\Columns\TextColumn::make('creator.name')
                    ->label('Creada por')
                    ->toggleable(isToggledHiddenByDefault: true),

                \App\Filament\Support\BranchTableHelpers::column(),
            ])
            ->defaultSort('created_at', 'desc')
            ->filters([
                Tables\Filters\SelectFilter::make('status')
                    ->label('Estado')
                    ->options([
                        'pendiente' => 'Pendiente',
                        'en_preparacion' => 'En Preparación',
                        'lista' => 'Lista',
                        'completada' => 'Completada',
                        'cancelada' => 'Cancelada',
                    ]),
                Tables\Filters\SelectFilter::make('order_type')
                    ->label('Tipo')
                    ->options([
                        'por_nombre' => 'Por Nombre',
                        'por_numero' => 'Por Número',
                    ]),
                \App\Filament\Support\BranchTableHelpers::filter(),
                Tables\Filters\TrashedFilter::make(),
            ])
            ->actions([
                Tables\Actions\Action::make('cambiar_estado')
                    ->label('Cambiar Estado')
                    ->icon('heroicon-o-arrow-path')
                    ->form([
                        Forms\Components\Select::make('status')
                            ->label('Nuevo Estado')
                            ->options([
                                'pendiente' => 'Pendiente',
                                'en_preparacion' => 'En Preparación',
                                'lista' => 'Lista',
                                'completada' => 'Completada',
                                'cancelada' => 'Cancelada',
                            ])
                            ->required(),
                    ])
                    ->action(function (Order $record, array $data): void {
                        try {
                            $record->update([
                                'status' => $data['status'],
                                'completed_at' => $data['status'] === 'completada' ? now() : $record->completed_at,
                                'ready_at' => $data['status'] === 'lista' ? now() : $record->ready_at,
                            ]);
                        } catch (\Throwable $e) {
                            Notification::make()
                                ->title('No se pudo cambiar el estado')
                                ->body($e->getMessage())
                                ->danger()
                                ->persistent()
                                ->send();

                            return;
                        }

                        Notification::make()
                            ->title('Estado actualizado')
                            ->success()
                            ->send();
                    }),

                Tables\Actions\Action::make('enviar_cocina')
                    ->label('Enviar a cocina')
                    ->icon('heroicon-o-fire')
                    ->color('warning')
                    ->visible(function (Order $record): bool {
                        $type = $record->business?->business_type
                            ?? filament()->getTenant()?->business_type;

                        return $type === 'cafeteria'
                            && (string) $record->status === 'pendiente'
                            && empty($record->converted_sale_id);
                    })
                    ->requiresConfirmation()
                    ->modalHeading('Entregar a cocina')
                    ->modalDescription('Se descontarán los ingredientes de la receta del inventario.')
                    ->action(function (Order $record): void {
                        try {
                            $record->update(['status' => 'en_preparacion']);
                            Notification::make()->title('Orden en cocina')->success()->send();
                        } catch (\Throwable $e) {
                            Notification::make()
                                ->title('Sin stock para cocina')
                                ->body($e->getMessage())
                                ->danger()
                                ->persistent()
                                ->send();
                        }
                    }),

                Tables\Actions\Action::make('marcar_lista')
                    ->label('Marcar lista')
                    ->icon('heroicon-o-check-circle')
                    ->color('info')
                    ->visible(function (Order $record): bool {
                        $type = $record->business?->business_type
                            ?? filament()->getTenant()?->business_type;

                        return $type === 'cafeteria'
                            && (string) $record->status === 'en_preparacion'
                            && empty($record->converted_sale_id);
                    })
                    ->action(function (Order $record): void {
                        $record->update([
                            'status' => 'lista',
                            'ready_at' => now(),
                        ]);
                        Notification::make()->title('Orden lista para cobrar')->success()->send();
                    }),

                Tables\Actions\Action::make('confirmar_ticket')
                    ->label('Confirmar y emitir ticket')
                    ->icon('heroicon-o-ticket')
                    ->color('success')
                    ->visible(fn (Order $record): bool => empty($record->converted_sale_id)
                        && ! in_array((string) $record->status, ['cancelada', 'completada'], true))
                    ->url(fn (Order $record) => route('receipts.orders.print', [
                        'tenant' => Filament::getTenant(),
                        'order' => $record,
                    ]))
                    ->openUrlInNewTab(),

                Tables\Actions\Action::make('cobrar_cuenta')
                    ->label('Cobrar cuenta')
                    ->icon('heroicon-o-banknotes')
                    ->color('success')
                    ->visible(function (Order $record): bool {
                        if (! (auth()->user()?->canPerform('create_sales') ?? false)) {
                            return false;
                        }
                        $type = $record->business?->business_type
                            ?? filament()->getTenant()?->business_type;

                        return $type === 'cafeteria'
                            && empty($record->converted_sale_id)
                            && in_array((string) $record->status, ['pendiente', 'en_preparacion', 'lista'], true);
                    })
                    ->requiresConfirmation()
                    ->modalHeading('Cobrar esta cuenta')
                    ->modalDescription('Se genera la venta. Si ya fue a cocina, el inventario no se vuelve a descontar.')
                    ->action(function (Order $record): void {
                        try {
                            $sale = app(\App\Services\SaleService::class)->convertFromOrder($record, [
                                'payment_method' => 'efectivo',
                                'amount_paid' => (float) ($record->total ?? 0),
                            ]);

                            Notification::make()
                                ->title('Cuenta cobrada')
                                ->body('Venta '.$sale->sale_number)
                                ->success()
                                ->send();
                        } catch (\Throwable $e) {
                            Notification::make()
                                ->title('No se pudo cobrar')
                                ->body($e->getMessage())
                                ->danger()
                                ->persistent()
                                ->send();
                        }
                    }),

                Tables\Actions\Action::make('convertir_venta')
                    ->label('Pasar a venta')
                    ->icon('heroicon-o-shopping-cart')
                    ->color('success')
                    ->visible(function (Order $record): bool {
                        if (! (auth()->user()?->canPerform('create_sales') ?? false)) {
                            return false;
                        }
                        $statusOk = in_array((string) $record->status, ['draft', 'sent', 'accepted', 'pendiente', 'en_preparacion'], true);
                        return $statusOk && empty($record->converted_sale_id) && (! empty($record->quote_number) || $record->business?->business_type === 'repuestos');
                    })
                    ->requiresConfirmation()
                    ->modalHeading('Convertir cotización en venta')
                    ->modalDescription('Se generará una venta con los mismos productos y se marcará la cotización como convertida.')
                    ->action(function (Order $record): void {
                        try {
                            $sale = app(\App\Services\SaleService::class)->convertFromOrder($record, [
                                'payment_method' => 'efectivo',
                                'amount_paid' => (float) ($record->total ?? 0),
                            ]);

                            Notification::make()
                                ->title('Venta creada')
                                ->body('Se generó la venta '.$sale->sale_number)
                                ->success()
                                ->send();
                        } catch (\Throwable $e) {
                            Notification::make()
                                ->title('No se pudo convertir')
                                ->body($e->getMessage())
                                ->danger()
                                ->persistent()
                                ->send();
                        }
                    }),

                Tables\Actions\ActionGroup::make([
                    Tables\Actions\Action::make('imprimir_ticket')
                        ->label('Imprimir Ticket')
                        ->icon('heroicon-o-printer')
                        ->color('primary')
                        ->url(fn (Order $record) => route('receipts.orders.print', [
                            'tenant' => Filament::getTenant(),
                            'order' => $record,
                        ]))
                        ->openUrlInNewTab(),
                    Tables\Actions\Action::make('descargar_pdf')
                        ->label(fn (Order $record): string => (! empty($record->quote_number) || $record->business?->business_type === 'repuestos') ? 'PDF Cotización' : 'Descargar PDF')
                        ->icon('heroicon-o-arrow-down-tray')
                        ->color('gray')
                        ->url(fn (Order $record) => route('receipts.orders.pdf', [
                            'tenant' => Filament::getTenant(),
                            'order' => $record,
                        ]))
                        ->openUrlInNewTab(),
                ])->label('Ticket')->icon('heroicon-o-ticket'),

                Tables\Actions\EditAction::make(),
                Tables\Actions\DeleteAction::make(),
            ])
            ->bulkActions([
                Tables\Actions\BulkActionGroup::make([
                    Tables\Actions\DeleteBulkAction::make(),
                ]),
            ])
            ->emptyStateHeading('Sin órdenes')
            ->emptyStateDescription('Crea una orden por nombre del cliente o por número automático.')
            ->emptyStateIcon('heroicon-o-clipboard-document-list');
    }

    public static function getRelations(): array
    {
        return [];
    }

    public static function getPages(): array
    {
        return [
            'index' => Pages\ListOrders::route('/'),
            'create' => Pages\CreateOrder::route('/create'),
            'edit' => Pages\EditOrder::route('/{record}/edit'),
        ];
    }

    public static function getEloquentQuery(): Builder
    {
        // Global Scope BelongsToBusiness ya filtra por tenant.
        // Solo se quita SoftDeletingScope para TrashedFilter.
        return parent::getEloquentQuery()
            ->withoutGlobalScopes([
                SoftDeletingScope::class,
            ]);
    }

    public static function getNavigationBadge(): ?string
    {
        return (string) static::getEloquentQuery()
            ->whereIn('status', ['pendiente', 'en_preparacion', 'lista'])
            ->count();
    }

    public static function getNavigationBadgeColor(): ?string
    {
        return 'warning';
    }

    public static function canViewAny(): bool
    {
        if (! static::tenantHasModule()) {
            return false;
        }

        return auth()->user()?->canPerform('view_orders') ?? false;
    }

    public static function canCreate(): bool
    {
        if (! static::tenantHasModule()) {
            return false;
        }

        return auth()->user()?->canPerform('create_orders') ?? false;
    }

    public static function canEdit($record): bool
    {
        if (! static::tenantHasModule()) {
            return false;
        }

        return auth()->user()?->canPerform('edit_orders') ?? false;
    }

    public static function canDelete($record): bool
    {
        if (! static::tenantHasModule()) {
            return false;
        }

        return auth()->user()?->hasRoleInBusiness(['owner', 'admin']) ?? false;
    }

}
