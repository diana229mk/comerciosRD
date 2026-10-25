<?php

namespace App\Filament\Resources;

use App\Enums\InventoryBehavior;
use App\Enums\InventoryMovementType;
use App\Filament\Concerns\ChecksBusinessModule;
use App\Filament\Resources\ProductResource\Pages;
use App\Filament\Resources\ProductResource\RelationManagers;
use App\Models\Product;
use App\Models\ProductCategory;
use App\Services\InventoryService;
use Filament\Facades\Filament;
use Filament\Forms;
use Filament\Forms\Form;
use Filament\Forms\Get;
use Filament\Notifications\Notification;
use Filament\Resources\Resource;
use Filament\Tables;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\SoftDeletingScope;
use Illuminate\Support\HtmlString;
use Illuminate\Validation\Rules\Unique;

class ProductResource extends Resource
{
    use ChecksBusinessModule;

    protected static string $businessModule = 'products';

    protected static string $viewPermission = 'view_products';
    protected static string $createPermission = 'create_products';
    protected static string $editPermission = 'edit_products';
    protected static ?string $deletePermission = null;

    protected static ?string $model = Product::class;

    protected static ?string $navigationIcon = 'heroicon-o-cube';

    protected static ?string $navigationLabel = 'Productos';

    protected static ?string $modelLabel = 'producto';

    protected static ?string $pluralModelLabel = 'productos';

    protected static ?string $navigationGroup = 'Inventario';

    protected static ?int $navigationSort = 1;

    /**
     * Tipo de negocio del tenant actual (o null si no hay).
     * Fuente única de verdad para filtrar unidades, categorías u otros
     * catálogos por giro sin repetir la resolución en cada sitio.
     */
    protected static function currentBusinessType(): ?\App\Enums\BusinessType
    {
        $tenant = filament()->getTenant();
        $type = $tenant?->type ?? null;

        return $type instanceof \App\Enums\BusinessType ? $type : null;
    }

    public static function getNavigationLabel(): string
    {
        return match (filament()->getTenant()?->business_type) {
            'farmacia' => 'Medicamentos',
            'ferreteria' => 'Artículos',
            'repuestos' => 'Repuestos / Piezas',
            'tienda' => 'Catálogo',
            'cafeteria' => 'Menú / Productos',
            'distribuidora', 'almacen' => 'Productos / Mayoreo',
            default => 'Productos',
        };
    }

    public static function getNavigationGroup(): ?string
    {
        return 'Inventario';
    }

    protected static ?string $recordTitleAttribute = 'name';

    protected static ?string $tenantOwnershipRelationshipName = 'business';

    public static function form(Form $form): Form
    {
        return $form
            ->schema([
                Forms\Components\Section::make('Información general')
                    ->schema([
                        Forms\Components\TextInput::make('name')
                            ->label('Nombre del producto')
                            ->required()
                            ->maxLength(255)
                            ->columnSpan(2),

                        Forms\Components\Select::make('category_id')
                            ->label('Categoría')
                            ->options(fn () => ProductCategory::query()
                                ->active()
                                ->with('parent')
                                ->orderBy('sort_order')
                                ->orderBy('name')
                                ->get()
                                ->mapWithKeys(fn (ProductCategory $c) => [
                                    $c->id => $c->full_name,
                                ])
                                ->all())
                            ->searchable()
                            ->preload()
                            ->nullable()
                            ->native(false)
                            ->createOptionForm([
                                Forms\Components\TextInput::make('name')
                                    ->label('Nombre')
                                    ->required(),
                                Forms\Components\ColorPicker::make('color')
                                    ->label('Color'),
                                Forms\Components\Select::make('parent_id')
                                    ->label('Categoría padre')
                                    ->options(fn () => ProductCategory::query()
                                        ->roots()
                                        ->active()
                                        ->orderBy('name')
                                        ->pluck('name', 'id'))
                                    ->nullable(),
                            ])
                            ->createOptionUsing(function (array $data): string {
                                $cat = ProductCategory::query()->create([
                                    'name' => $data['name'],
                                    'color' => $data['color'] ?? null,
                                    'parent_id' => $data['parent_id'] ?? null,
                                    'is_active' => true,
                                ]);

                                return (string) $cat->getKey();
                            })
                            ->helperText('Ej: Bebidas, Medicamentos, Materias Primas. Puedes crear categorías al vuelo.'),

                        Forms\Components\TextInput::make('sku')
                            ->label('SKU / Código interno')
                            ->maxLength(64)
                            ->nullable()
                            ->unique(
                                table: Product::class,
                                column: 'sku',
                                ignoreRecord: true,
                                modifyRuleUsing: function (Unique $rule): Unique {
                                    $businessId = Filament::getTenant()?->getKey()
                                        ?? session('current_business_id');

                                    return $rule->where('business_id', $businessId);
                                },
                            ),

                        Forms\Components\Section::make('Tienda / boutique')
                            ->visible(fn (): bool => (filament()->getTenant()?->isRetailStore() ?? false))
                            ->schema([
                                Forms\Components\Select::make('season_id')
                                    ->label('Temporada / Colección')
                                    ->relationship('season', 'name')
                                    ->searchable()
                                    ->preload(),
                                Forms\Components\TextInput::make('collection')
                                    ->label('Colección')
                                    ->maxLength(100),
                                Forms\Components\TextInput::make('style')
                                    ->label('Estilo / modelo')
                                    ->maxLength(100),
                                Forms\Components\Toggle::make('is_liquidation')
                                    ->label('Liquidación')
                                    ->default(false),
                            ])
                            ->columns(2)
                            ->columnSpanFull(),

                        Forms\Components\Section::make('Datos de repuesto')
                            ->visible(fn (): bool => (filament()->getTenant()?->business_type ?? null) === 'repuestos')
                            ->schema([
                                Forms\Components\TextInput::make('part_brand')
                                    ->label('Marca de la pieza')
                                    ->placeholder('Bosch, NGK, Gates...'),
                                Forms\Components\TextInput::make('oem_code')
                                    ->label('Código OEM / Original')
                                    ->placeholder('Ej: 90915-YZZD2'),
                                Forms\Components\Textarea::make('alt_codes')
                                    ->label('Códigos alternos')
                                    ->helperText('Separados por coma')
                                    ->rows(2),
                                Forms\Components\TextInput::make('bin_location')
                                    ->label('Ubicación (anaquel)')
                                    ->placeholder('A-12 / Pasillo 3'),
                                Forms\Components\TextInput::make('wholesale_price')
                                    ->label('Precio mayoreo')
                                    ->numeric()
                                    ->prefix('RD$'),
                                Forms\Components\TextInput::make('vehicle_make')
                                    ->label('Marca del vehículo')
                                    ->placeholder('Toyota'),
                                Forms\Components\TextInput::make('vehicle_model')
                                    ->label('Modelo')
                                    ->placeholder('Corolla'),
                                Forms\Components\TextInput::make('year_from')
                                    ->label('Año desde')
                                    ->numeric()
                                    ->minValue(1980)
                                    ->maxValue(2030),
                                Forms\Components\TextInput::make('year_to')
                                    ->label('Año hasta')
                                    ->numeric()
                                    ->minValue(1980)
                                    ->maxValue(2030),
                                Forms\Components\TextInput::make('engine')
                                    ->label('Motor')
                                    ->placeholder('1.8 / 2.0 Diesel'),
                                Forms\Components\TextInput::make('position')
                                    ->label('Posición')
                                    ->placeholder('Delantero, trasero, izq...'),
                            ])
                            ->columns(2)
                            ->columnSpanFull(),

                        Forms\Components\TextInput::make('barcode')
                            ->label('Código de barras')
                            ->maxLength(64)
                            ->extraInputAttributes([
                                'data-barcode-scan' => '1',
                                'autocomplete' => 'off',
                            ])
                            ->helperText('Pistola USB: dispara al campo. En el POS, escanea con el recuadro de búsqueda enfocado o en cualquier parte de la caja.'),

                        Forms\Components\Select::make('unit')
                            ->label('Unidad de medida')
                            ->options(function ($record) {
                                $options = Product::unitOptions(self::currentBusinessType());

                                // Preserva la unidad del registro si es legacy o de otro giro
                                // (evita que "desaparezca" al editar y romper el form).
                                $current = $record?->unit;
                                if ($current && ! array_key_exists($current, $options)) {
                                    $all = Product::allUnits();
                                    $options[$current] = ($all[$current] ?? $current).' (heredada)';
                                }

                                return $options;
                            })
                            ->required()
                            ->default('unidad')
                            ->native(false),

                        Forms\Components\TextInput::make('image_url')
                            ->label('URL de imagen')
                            ->placeholder('https://...')
                            ->url()
                            ->nullable()
                            ->afterStateHydrated(function (Forms\Components\TextInput $component, ?Product $record): void {
                                if (! $record) {
                                    return;
                                }

                                $image = (string) ($record->image ?? '');
                                $component->state(str_starts_with($image, 'http') ? $image : null);
                            })
                            ->helperText('Pega una URL directa o usa la imagen subida. Si se llena la URL, tendrá prioridad.')
                            ->columnSpan(2),

                        Forms\Components\FileUpload::make('image')
                            ->label('Subir imagen')
                            ->image()
                            ->disk('public')
                            ->directory(fn (): string => \App\Support\TenantStorage::directory('products'))
                            ->imageEditor()
                            ->imagePreviewHeight('150')
                            ->maxSize(2048)
                            ->columnSpan(2)
                            ->helperText('JPG/PNG hasta 2 MB. Si no usas URL, se guarda por negocio.'),

                        Forms\Components\Textarea::make('description')
                            ->label('Descripción')
                            ->rows(3)
                            ->columnSpanFull(),
                    ])
                    ->columns(3),

                Forms\Components\Section::make('Precios (DOP)')
                    ->schema([
                        Forms\Components\TextInput::make('price')
                            ->label('Precio de venta')
                            ->numeric()
                            ->prefix('RD$')
                            ->required()
                            ->minValue(0)
                            ->default(0)
                            ->helperText('El monto que ves en góndola. El ITBIS se configura abajo o en Datos del negocio.'),

                        Forms\Components\Select::make('price_includes_tax')
                            ->label('ITBIS en este precio')
                            ->options([
                                '' => 'Usar config del negocio',
                                '1' => 'Ya incluye ITBIS (el cliente paga este monto)',
                                '0' => 'No incluye: la caja le suma el ITBIS',
                            ])
                            ->default('')
                            ->dehydrated()
                            ->dehydrateStateUsing(function ($state) {
                                if ($state === '' || $state === null) {
                                    return null;
                                }

                                return (bool) ((int) $state);
                            })
                            ->formatStateUsing(function ($state) {
                                if ($state === null) {
                                    return '';
                                }

                                return $state ? '1' : '0';
                            })
                            ->native(false),

                        Forms\Components\TextInput::make('cost')
                            ->label('Costo')
                            ->numeric()
                            ->prefix('RD$')
                            ->minValue(0)
                            ->default(0)
                            ->visible(function (?Product $record): bool {
                                $user = auth()->user();
                                if (! $user) {
                                    return false;
                                }
                                if ($record) {
                                    return $user->can('viewCost', $record);
                                }
                                $tenant = Filament::getTenant();
                                if (! $tenant) {
                                    return false;
                                }
                                $role = $user->roleInBusiness((string) $tenant->getKey());

                                return in_array($role, ['owner', 'admin', 'manager'], true);
                            }),

                        Forms\Components\Placeholder::make('margin_hint')
                            ->label('Margen estimado')
                            ->content(function (Get $get): string {
                                $price = (float) ($get('price') ?? 0);
                                $cost = (float) ($get('cost') ?? 0);
                                if ($price <= 0) {
                                    return '—';
                                }
                                $margin = $price - $cost;
                                $pct = $price > 0 ? ($margin / $price) * 100 : 0;

                                return 'RD$ '.number_format($margin, 2).' ('.number_format($pct, 1).'%)';
                            })
                            ->visible(function (): bool {
                                $user = auth()->user();
                                $tenant = Filament::getTenant();
                                if (! $user || ! $tenant) {
                                    return false;
                                }

                                return in_array(
                                    $user->roleInBusiness((string) $tenant->getKey()),
                                    ['owner', 'admin', 'manager'],
                                    true
                                );
                            }),
                    ])
                    ->columns(3),

                Forms\Components\Section::make('Inventario')
                    ->schema([
                        Forms\Components\Toggle::make('track_stock')
                            ->label('Controlar inventario')
                            ->default(true)
                            ->live()
                            ->helperText('Si está apagado, no se lleva control de stock de este producto')
                            ->columnSpanFull(),

                        Forms\Components\Select::make('inventory_behavior')
                            ->label('Comportamiento de Inventario')
                            ->options([
                                InventoryBehavior::DeductOnSale->value => 'Descontar stock al vender',
                                InventoryBehavior::DoNotDeduct->value => 'No descontar stock al vender (materia prima)',
                            ])
                            ->default(function (): string {
                                $tenant = Filament::getTenant();
                                $type = $tenant?->type ?? null;

                                return InventoryBehavior::defaultForBusinessType($type)->value;
                            })
                            ->required()
                            ->native(false)
                            ->live()
                            ->helperText(fn (Get $get): string => match ($get('inventory_behavior')) {
                                InventoryBehavior::DoNotDeduct->value,
                                'do_not_deduct' => 'Ideal para materias primas (ej: lechuga, pan, carne en cafeterías). No se descuenta al vender.',
                                default => 'Ideal para productos terminados (farmacias, colmados, ferreterías). Se descuenta al vender.',
                            })
                            ->visible(fn (Get $get): bool => (bool) $get('track_stock'))
                            ->columnSpanFull(),

                        Forms\Components\TextInput::make('stock')
                            ->label('Stock actual')
                            ->numeric()
                            ->default(0)
                            ->minValue(0)
                            ->helperText('También puedes ajustar stock desde la tabla sin editar todo el producto')
                            ->visible(fn (Get $get): bool => (bool) $get('track_stock')),

                        Forms\Components\TextInput::make('min_stock')
                            ->label('Stock mínimo (alerta)')
                            ->numeric()
                            ->default(0)
                            ->minValue(0)
                            ->visible(fn (Get $get): bool => (bool) $get('track_stock')),

                        Forms\Components\DatePicker::make('expires_at')
                            ->label('Fecha de vencimiento')
                            ->native(false)
                            ->nullable()
                            ->helperText(fn (): string => match (filament()->getTenant()?->typeKey()) {
                                'farmacia' => 'Importante: control de lotes y alertas de vencimiento',
                                default => 'Útil en farmacias / alimentos',
                            })
                            ->visible(function (Get $get): bool {
                                if (! (bool) $get('track_stock')) {
                                    return false;
                                }
                                // Siempre en farmacia; en otros tipos opcional pero visible si track_stock
                                return true;
                            })
                            ->required(fn (): bool => filament()->getTenant()?->type?->tracksExpiry() ?? false),
                    ])
                    ->columns(3),

                Forms\Components\Section::make('Estado')
                    ->schema([
                        Forms\Components\Toggle::make('is_active')
                            ->label('Producto activo')
                            ->default(true)
                            ->helperText('Los inactivos no aparecen en el POS ni en el escáner'),

                        Forms\Components\Toggle::make('requires_prescription')
                            ->label('Requiere receta médica')
                            ->helperText('Solo se puede vender asociando una receta activa (farmacia).')
                            ->default(false)
                            ->visible(fn (): bool => filament()->getTenant()?->isPharmacy() ?? false),

                        Forms\Components\Select::make('controlled_type')
                            ->label('Clasificación controlada')
                            ->options([
                                'anexo_i' => 'Anexo I',
                                'anexo_ii' => 'Anexo II',
                                'anexo_iii' => 'Anexo III',
                                'psicotropico' => 'Psicotrópico',
                            ])
                            ->native(false)
                            ->placeholder('No controlado')
                            ->visible(fn (): bool => filament()->getTenant()?->isPharmacy() ?? false),

                        // ---- Ferretería ----
                        Forms\Components\Toggle::make('is_kit')
                            ->label(fn (): string => (filament()->getTenant()?->isCafeteria() ?? false)
                                ? 'Es un plato con receta'
                                : 'Es un kit / combo')
                            ->helperText(fn (): string => (filament()->getTenant()?->isCafeteria() ?? false)
                                ? 'Al enviar la orden a cocina se descuentan los ingredientes.'
                                : 'Al vender, descuenta stock de los componentes.')
                            ->visible(fn (): bool => (filament()->getTenant()?->usesAdvancedInventory() ?? false)
                                || (filament()->getTenant()?->usesKitchenRecipes() ?? false)),

                        Forms\Components\Toggle::make('tracks_serial')
                            ->label('Rastrear serie / IMEI')
                            ->helperText('Herramientas eléctricas o equipos de valor.')
                            ->visible(fn (): bool => filament()->getTenant()?->usesAdvancedInventory() ?? false),

                        Forms\Components\TextInput::make('brand')->label('Marca')
                            ->visible(fn (): bool => filament()->getTenant()?->usesAdvancedInventory() ?? false),

                        Forms\Components\TextInput::make('supplier_code')->label('Código proveedor')
                            ->visible(fn (): bool => filament()->getTenant()?->usesAdvancedInventory() ?? false),

                        Forms\Components\TextInput::make('max_stock')->label('Stock máximo')->numeric()
                            ->visible(fn (): bool => filament()->getTenant()?->usesAdvancedInventory() ?? false),

                        Forms\Components\TagsInput::make('search_aliases')
                            ->label('Sinónimos / búsqueda alternativa')
                            ->helperText('Enter para agregar. Ej: "clavo galvanizado", "clavo galv", "clavo 3 pulgadas".')
                            ->visible(fn (): bool => filament()->getTenant()?->usesAdvancedInventory() ?? false)
                            ->columnSpanFull(),
                    ])
                    ->columns(3),
            ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                Tables\Columns\ImageColumn::make('image')
                    ->label('Imagen')
                    ->disk('public')
                    ->circular()
                    ->toggleable(),

                Tables\Columns\TextColumn::make('name')
                    ->label('Producto')
                    ->searchable()
                    ->sortable()
                    ->weight('bold')
                    ->description(function (Product $record): ?string {
                        $parts = [];
                        if (filled($record->sku)) {
                            $parts[] = 'SKU: '.$record->sku;
                        }
                        if (filled($record->barcode)) {
                            $parts[] = 'Código: '.$record->barcode;
                        }

                        return $parts !== [] ? implode(' · ', $parts) : null;
                    }),

                Tables\Columns\TextColumn::make('category.full_name')
                    ->label('Categoría')
                    ->badge()
                    ->color('primary')
                    ->placeholder('Sin categoría')
                    ->sortable()
                    ->toggleable()
                    ->tooltip(fn (Product $record): ?string => $record->category?->full_name)
                    ->searchable(query: function (Builder $query, string $search): Builder {
                        return $query->whereHas('category', function (Builder $q) use ($search): void {
                            $q->where('name', 'like', "%{$search}%");
                        });
                    }),

                Tables\Columns\TextColumn::make('stock')
                    ->label('Stock Actual')
                    ->sortable()
                    ->weight('bold')
                    ->formatStateUsing(fn (Product $record): string => $record->track_stock
                        ? number_format((float) $record->stock, 0).' '.($record->unit ?? '')
                        : 'N/A'
                    )
                    ->color(function (Product $record): string {
                        if (! $record->track_stock) {
                            return 'gray';
                        }
                        $stock = (float) $record->stock;
                        $min = (float) $record->min_stock;
                        if ($stock <= 0) {
                            return 'danger'; // agotado
                        }
                        if ($stock <= $min) {
                            return 'warning'; // bajo / cerca del mínimo
                        }

                        return 'success'; // normal
                    })
                    ->icon(function (Product $record): ?string {
                        if (! $record->track_stock) {
                            return null;
                        }
                        $stock = (float) $record->stock;
                        $min = (float) $record->min_stock;
                        if ($stock <= 0) {
                            return 'heroicon-m-x-circle';
                        }
                        if ($stock <= $min) {
                            return 'heroicon-m-exclamation-triangle';
                        }

                        return 'heroicon-m-check-circle';
                    }),

                Tables\Columns\TextColumn::make('stock_status')
                    ->label('Indicador')
                    ->badge()
                    ->state(fn (Product $record): string => $record->stockStatusLabel())
                    ->color(fn (Product $record): string => $record->stockStatusColor())
                    ->toggleable(),

                Tables\Columns\TextColumn::make('min_stock')
                    ->label('Stock Mínimo')
                    ->sortable()
                    ->toggleable(isToggledHiddenByDefault: true)
                    ->formatStateUsing(fn (Product $record): string => $record->track_stock
                        ? number_format((float) $record->min_stock, 0)
                        : '—'),

                Tables\Columns\TextColumn::make('oem_code')
                    ->label('OEM')
                    ->searchable()
                    ->toggleable()
                    ->visible(fn (): bool => (filament()->getTenant()?->business_type ?? null) === 'repuestos'),

                Tables\Columns\TextColumn::make('part_brand')
                    ->label('Marca')
                    ->toggleable()
                    ->visible(fn (): bool => (filament()->getTenant()?->business_type ?? null) === 'repuestos'),

                Tables\Columns\TextColumn::make('vehicle_display')
                    ->label('Vehículo')
                    ->state(function (Product $record): string {
                        if (! filled($record->vehicle_make)) {
                            return '—';
                        }

                        $year = trim(($record->year_from ?? '').'-'.($record->year_to ?? ''), '-');

                        return trim("{$record->vehicle_make} {$record->vehicle_model} {$year}");
                    })
                    ->toggleable()
                    ->visible(fn (): bool => (filament()->getTenant()?->business_type ?? null) === 'repuestos'),

                Tables\Columns\TextColumn::make('bin_location')
                    ->label('Ubicación')
                    ->toggleable()
                    ->visible(fn (): bool => (filament()->getTenant()?->business_type ?? null) === 'repuestos'),

                Tables\Columns\TextColumn::make('wholesale_price')
                    ->label('Mayoreo')
                    ->money('DOP')
                    ->toggleable()
                    ->visible(fn (): bool => (filament()->getTenant()?->business_type ?? null) === 'repuestos'),

                Tables\Columns\TextColumn::make('price')
                    ->label('Precio')
                    ->money('DOP')
                    ->sortable(),

                Tables\Columns\TextColumn::make('cost')
                    ->label('Costo')
                    ->money('DOP')
                    ->sortable()
                    ->toggleable(isToggledHiddenByDefault: true)
                    ->visible(function (): bool {
                        $user = auth()->user();
                        $tenant = Filament::getTenant();
                        if (! $user || ! $tenant) {
                            return false;
                        }

                        return in_array(
                            $user->roleInBusiness((string) $tenant->getKey()),
                            ['owner', 'admin', 'manager'],
                            true
                        );
                    }),

                Tables\Columns\TextColumn::make('inventory_behavior')
                    ->label('Comportamiento')
                    ->badge()
                    ->formatStateUsing(function ($state): string {
                        $value = $state instanceof InventoryBehavior
                            ? $state->value
                            : (is_string($state) ? $state : null);

                        return match ($value) {
                            InventoryBehavior::DeductOnSale->value, 'deduct_on_sale' => 'Descuenta al vender',
                            InventoryBehavior::DoNotDeduct->value, 'do_not_deduct' => 'No descuenta (Materia Prima)',
                            default => $value ?: '—',
                        };
                    })
                    ->color(function ($state): string {
                        $value = $state instanceof InventoryBehavior
                            ? $state->value
                            : (is_string($state) ? $state : '');

                        return match ($value) {
                            'deduct_on_sale' => 'success',
                            'do_not_deduct' => 'warning',
                            default => 'gray',
                        };
                    })
                    ->toggleable(),

                Tables\Columns\TextColumn::make('barcode')
                    ->label('Código de barras')
                    ->searchable()
                    ->toggleable(isToggledHiddenByDefault: true)
                    ->placeholder('—'),

                Tables\Columns\TextColumn::make('unit')
                    ->label('Unidad')
                    ->badge()
                    ->toggleable(isToggledHiddenByDefault: true),

                Tables\Columns\IconColumn::make('is_active')
                    ->label('Activo')
                    ->boolean()
                    ->sortable(),

                Tables\Columns\TextColumn::make('updated_at')
                    ->label('Última actualización')
                    ->dateTime('d/m/Y H:i')
                    ->sortable()
                    ->toggleable(isToggledHiddenByDefault: true),
            ])
            ->defaultSort('name')
            ->filters([
                Tables\Filters\SelectFilter::make('category_id')
                    ->label('Categoría')
                    ->relationship('category', 'name')
                    ->searchable()
                    ->preload(),

                Tables\Filters\SelectFilter::make('inventory_behavior')
                    ->label('Comportamiento de Inventario')
                    ->options([
                        InventoryBehavior::DeductOnSale->value => 'Descuenta al vender',
                        InventoryBehavior::DoNotDeduct->value => 'No descuenta (Materia Prima)',
                    ]),

                Tables\Filters\TernaryFilter::make('is_active')
                    ->label('Estado')
                    ->placeholder('Todos')
                    ->trueLabel('Solo activos')
                    ->falseLabel('Solo inactivos'),

                Tables\Filters\Filter::make('stock_bajo')
                    ->label('Stock Bajo o Agotado')
                    ->query(fn (Builder $query): Builder => $query
                        ->where('track_stock', true)
                        ->whereColumn('stock', '<=', 'min_stock'))
                    ->toggle(),

                Tables\Filters\Filter::make('sin_stock')
                    ->label('Sin Stock')
                    ->query(fn (Builder $query): Builder => $query
                        ->where('track_stock', true)
                        ->where('stock', '<=', 0))
                    ->toggle(),

                Tables\Filters\SelectFilter::make('unit')
                    ->label('Unidad')
                    ->options(fn () => Product::unitOptions(self::currentBusinessType())),

                Tables\Filters\TrashedFilter::make(),
            ])
            ->actions([
                Tables\Actions\Action::make('ajustar_stock')
                    ->label('Ajustar Stock')
                    ->icon('heroicon-o-adjustments-horizontal')
                    ->color('warning')
                    ->visible(fn (Product $record): bool => (bool) $record->track_stock)
                    ->modalHeading('Ajustar Stock del Producto')
                    ->modalDescription(fn (Product $record): string => 'Producto: '.$record->name)
                    ->modalSubmitActionLabel('Confirmar ajuste')
                    // Precarga stock/unidad en el form (evita fallos al inyectar $record en Placeholders)
                    ->fillForm(fn (Product $record): array => [
                        'current_stock' => (float) $record->stock,
                        'unit_label' => (string) ($record->unit ?: 'unidad'),
                        'type' => InventoryMovementType::AdjustmentIn->value,
                        'quantity' => null,
                        'reason' => null,
                    ])
                    ->form([
                        Forms\Components\Hidden::make('current_stock'),
                        Forms\Components\Hidden::make('unit_label'),

                        Forms\Components\Section::make('Información Actual')
                            ->schema([
                                Forms\Components\Placeholder::make('stock_actual')
                                    ->label('Stock Actual')
                                    ->content(function (Get $get): string {
                                        $stock = (float) ($get('current_stock') ?? 0);
                                        $unit = (string) ($get('unit_label') ?: 'unidad');

                                        return number_format($stock, 3).' '.$unit;
                                    }),
                            ]),

                        Forms\Components\Section::make('Ajuste de Stock')
                            ->schema([
                                Forms\Components\Select::make('type')
                                    ->label('Tipo de Movimiento')
                                    ->options([
                                        InventoryMovementType::AdjustmentIn->value => 'Entrada de Stock (+)',
                                        InventoryMovementType::AdjustmentOut->value => 'Salida de Stock (-)',
                                    ])
                                    ->required()
                                    ->native(false)
                                    ->live(),

                                Forms\Components\TextInput::make('quantity')
                                    ->label('Cantidad')
                                    ->numeric()
                                    ->required()
                                    ->minValue(0.001)
                                    ->step(0.001)
                                    ->live(debounce: 300),

                                Forms\Components\Placeholder::make('nuevo_stock')
                                    ->label('Stock después del ajuste')
                                    ->content(function (Get $get): HtmlString|string {
                                        $type = $get('type');
                                        $quantity = (float) ($get('quantity') ?? 0);
                                        $current = (float) ($get('current_stock') ?? 0);
                                        $unit = (string) ($get('unit_label') ?: 'unidad');

                                        if (! $type || $quantity <= 0) {
                                            return '—';
                                        }

                                        $newStock = in_array($type, [
                                            InventoryMovementType::AdjustmentIn->value,
                                            'adjustment_in',
                                        ], true)
                                            ? $current + $quantity
                                            : $current - $quantity;

                                        $formatted = number_format($newStock, 3).' '.$unit;

                                        if ($newStock < 0) {
                                            return new HtmlString(
                                                '<span style="font-weight:700;color:#dc2626;">'
                                                .e($formatted)
                                                .' — No se permite stock negativo</span>'
                                            );
                                        }

                                        return new HtmlString(
                                            '<span style="font-weight:700;color:#16a34a;">'
                                            .e($formatted)
                                            .'</span>'
                                        );
                                    }),

                                Forms\Components\Textarea::make('reason')
                                    ->label('Motivo del Ajuste')
                                    ->required()
                                    ->rows(3)
                                    ->placeholder('Ej: Conteo físico, producto dañado, merma, corrección de error...'),
                            ]),
                    ])
                    ->action(function (Product $record, array $data): void {
                        try {
                            $type = (string) $data['type'];
                            $quantity = (float) $data['quantity'];
                            $before = (float) $record->stock;

                            if ($type === InventoryMovementType::AdjustmentOut->value && $quantity > $before) {
                                Notification::make()
                                    ->title('Stock insuficiente')
                                    ->body(
                                        'No puedes sacar más de lo disponible. '
                                        .'Stock actual: '.number_format($before, 3).' '.$record->unit
                                    )
                                    ->danger()
                                    ->send();

                                return;
                            }

                            // InventoryService: movimiento + actualización de stock (cantidad siempre positiva)
                            $movement = app(InventoryService::class)->adjust(
                                product: $record,
                                type: $type,
                                quantity: $quantity,
                                reason: (string) $data['reason'],
                            );

                            $record->refresh();

                            Notification::make()
                                ->title('Stock ajustado correctamente')
                                ->body(
                                    $record->name.': '
                                    .number_format($before, 3).' → '
                                    .number_format((float) $record->stock, 3).' '.$record->unit
                                    .' ('.$movement->type->label().')'
                                )
                                ->success()
                                ->send();
                        } catch (\Throwable $e) {
                            Notification::make()
                                ->title('No se pudo ajustar el stock')
                                ->body($e->getMessage())
                                ->danger()
                                ->send();
                        }
                    }),

                Tables\Actions\ViewAction::make(),
                Tables\Actions\EditAction::make(),
                Tables\Actions\DeleteAction::make(),
            ])
            ->bulkActions([
                Tables\Actions\BulkActionGroup::make([
                    Tables\Actions\DeleteBulkAction::make(),
                    Tables\Actions\ForceDeleteBulkAction::make(),
                    Tables\Actions\RestoreBulkAction::make(),
                ]),
            ])
            ->emptyStateHeading('Sin productos')
            ->emptyStateDescription('Crea el primer producto de este negocio.')
            ->emptyStateIcon('heroicon-o-cube');
    }

    public static function getRelations(): array
    {
        $tenant = filament()->getTenant();
        $relations = [
            RelationManagers\MovementsRelationManager::class,
        ];

        if ($tenant instanceof \App\Models\Business && $tenant->isPharmacy()) {
            $relations[] = RelationManagers\BatchesRelationManager::class;
        }

        if ($tenant instanceof \App\Models\Business && $tenant->usesAdvancedInventory()) {
            $relations[] = RelationManagers\VariantsRelationManager::class;
            $relations[] = RelationManagers\UnitsRelationManager::class;
            $relations[] = RelationManagers\SubstitutesRelationManager::class;
        }

        if ($tenant instanceof \App\Models\Business
            && ($tenant->usesAdvancedInventory() || $tenant->usesKitchenRecipes())) {
            $relations[] = RelationManagers\KitComponentsRelationManager::class;
        }

        return $relations;
    }

    public static function getPages(): array
    {
        return [
            'index' => Pages\ListProducts::route('/'),
            'create' => Pages\CreateProduct::route('/create'),
            'view' => Pages\ViewProduct::route('/{record}'),
            'edit' => Pages\EditProduct::route('/{record}/edit'),
        ];
    }

    public static function getEloquentQuery(): Builder
    {
        return parent::getEloquentQuery()
            ->withoutGlobalScopes([
                SoftDeletingScope::class,
            ]);
    }

    public static function getNavigationBadge(): ?string
    {
        return (string) static::getEloquentQuery()->count();
    }

    public static function canViewAny(): bool
    {
        if (! static::tenantHasModule()) {
            return false;
        }

        return auth()->user()?->canPerform('view_products') ?? false;
    }

    public static function canCreate(): bool
    {
        if (! static::tenantHasModule()) {
            return false;
        }

        // Tabla: owner, admin, supervisor
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
