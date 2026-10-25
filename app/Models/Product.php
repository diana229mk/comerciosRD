<?php

namespace App\Models;

use App\Enums\InventoryBehavior;
use App\Traits\BelongsToBusiness;
use App\Traits\ScopedByBranch;
use App\Traits\TracksBranch;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Facades\Storage;

/**
 * Producto aislado por tenant (business_id vía BelongsToBusiness).
 * Nunca se consulta sin Global Scope.
 */
class Product extends Model
{
    use BelongsToBusiness;
    use HasFactory;
    use HasUuids;
    use ScopedByBranch;
    use SoftDeletes;
    use TracksBranch;

    protected $fillable = [
        // business_id: lo inyecta el trait (NO fillable — anti mass-assignment)
        'category_id',
        'season_id',
        'collection',
        'style',
        'is_liquidation',
        'name',
        'part_brand',
        'oem_code',
        'alt_codes',
        'bin_location',
        'wholesale_price',
        'vehicle_make',
        'vehicle_model',
        'year_from',
        'year_to',
        'engine',
        'position',
        'barcode',
        'sku',
        'price',
        'price_includes_tax',
        'cost',
        'stock',
        'min_stock',
        'reorder_point',
        'reorder_quantity',
        'lead_time_days',
        'preferred_supplier_id',
        'unit',
        'description',
        'track_stock',
        'inventory_behavior',
        'is_active',
        'requires_prescription',
        'controlled_type',
        'image',
        'expires_at',
        'is_kit',
        'tracks_serial',
        'brand',
        'supplier_code',
        'max_stock',
        'search_aliases',
    ];

    protected function casts(): array
    {
        return [
            'price' => 'decimal:2',
            'cost' => 'decimal:2',
            'wholesale_price' => 'decimal:2',
            'stock' => 'decimal:3',
            'min_stock' => 'decimal:3',
            'reorder_point' => 'decimal:3',
            'reorder_quantity' => 'decimal:3',
            'lead_time_days' => 'integer',
            'track_stock' => 'boolean',
            'inventory_behavior' => InventoryBehavior::class,
            'is_active' => 'boolean',
            'requires_prescription' => 'boolean',
            'expires_at' => 'date',
            'is_kit' => 'boolean',
            'tracks_serial' => 'boolean',
            'is_liquidation' => 'boolean',
            'max_stock' => 'decimal:3',
            'search_aliases' => 'array',
        ];
    }

    /**
     * ¿El precio de este producto ya trae ITBIS?
     * null en el producto = usa la config del negocio (por defecto: no, se suma en caja).
     */
    public function priceIncludesTax(?Business $business = null): bool
    {
        $raw = $this->getAttributes()['price_includes_tax'] ?? $this->price_includes_tax;
        if ($raw !== null) {
            return (bool) $raw;
        }

        return $business?->pricesIncludeTax() ?? false;
    }

    public function category(): BelongsTo
    {
        return $this->belongsTo(ProductCategory::class, 'category_id');
    }

    public function preferredSupplier(): BelongsTo
    {
        return $this->belongsTo(Supplier::class, 'preferred_supplier_id');
    }

    public function season(): BelongsTo
    {
        return $this->belongsTo(ProductSeason::class, 'season_id');
    }

    public function generateVariantMatrix(array $sizes, array $colors, array $options = []): int
    {
        $sizes = array_values(array_filter(array_map(static fn ($size) => trim((string) $size), $sizes)));
        $colors = array_values(array_filter(array_map(static fn ($color) => trim((string) $color), $colors)));

        if ($sizes === [] || $colors === []) {
            return 0;
        }

        $stock = (float) ($options['stock'] ?? 0);
        $price = $options['price'] ?? $this->price;
        $cost = $options['cost'] ?? $this->cost;
        $barcodePrefix = (string) ($options['barcode_prefix'] ?? $this->sku ?? $this->barcode ?? 'VAR');
        $generated = 0;

        foreach ($sizes as $size) {
            foreach ($colors as $color) {
                $sizeKey = strtolower(str_replace([' ', '/'], '-', trim((string) $size)));
                $colorKey = strtolower(str_replace([' ', '/'], '-', trim((string) $color)));
                $sku = strtoupper(trim((string) ($this->sku ?: 'PRD'))).'-'.strtoupper($sizeKey).'-'.strtoupper($colorKey);
                $barcode = $barcodePrefix !== '' ? $barcodePrefix.'-'.strtoupper($sizeKey).'-'.strtoupper($colorKey) : null;

                $variant = $this->variants()->where('sku', $sku)->first();
                if (! $variant) {
                    $variant = new ProductVariant();
                    $variant->product_id = $this->getKey();
                    $this->variants()->save($variant);
                }

                $variant->forceFill([
                    'sku' => $sku,
                    'barcode' => $barcode,
                    'attributes' => [
                        'size' => $size,
                        'color' => $color,
                    ],
                    'price' => $price,
                    'cost' => $cost,
                    'stock' => $stock,
                    'is_active' => true,
                ]);
                $variant->save();
                $generated++;
            }
        }

        return $generated;
    }

    /**
     * Movimientos de inventario del producto (historial).
     */
    public function movements(): HasMany
    {
        return $this->hasMany(InventoryMovement::class)->latest();
    }

    /**
     * Alias de movements() (compatibilidad).
     */
    public function inventoryMovements(): HasMany
    {
        return $this->movements();
    }

    /**
     * Stock calculado desde movimientos (entradas − salidas).
     * El campo `stock` se mantiene como caché actualizado por InventoryService.
     */
    public function getCurrentStockAttribute(): float
    {
        $entryTypes = InventoryMovement::ENTRY_TYPES;
        $exitTypes = InventoryMovement::EXIT_TYPES;

        $row = $this->movements()
            ->selectRaw(
                'COALESCE(SUM(CASE WHEN type IN ('.$this->sqlInList($entryTypes).') THEN quantity ELSE 0 END), 0) as entries,
                 COALESCE(SUM(CASE WHEN type IN ('.$this->sqlInList($exitTypes).') THEN quantity ELSE 0 END), 0) as exits'
            )
            ->first();

        if (! $row) {
            return (float) ($this->attributes['stock'] ?? 0);
        }

        return round((float) $row->entries - (float) $row->exits, 3);
    }

    /**
     * Lista SQL IN segura: solo valores constantes alfanuméricos (tipos de movimiento).
     * Nunca pasar input de usuario aquí.
     *
     * @param  list<string>  $values
     */
    protected function sqlInList(array $values): string
    {
        $safe = collect($values)
            ->map(fn ($v) => (string) $v)
            ->filter(fn (string $v) => (bool) preg_match('/^[a-z0-9_]+$/i', $v))
            ->unique()
            ->values();

        if ($safe->isEmpty()) {
            return "''";
        }

        return $safe
            ->map(fn (string $v) => "'".$v."'")
            ->implode(',');
    }

    /**
     * Sincroniza el campo cacheado `stock` con la suma de movimientos.
     */
    public function refreshStockFromMovements(): float
    {
        $stock = $this->current_stock;
        $this->forceFill(['stock' => max(0, $stock)])->saveQuietly();

        return (float) $this->stock;
    }

    // ==================== COMPORTAMIENTO DE INVENTARIO ====================

    public const BEHAVIOR_DEDUCT_ON_SALE = 'deduct_on_sale';

    public const BEHAVIOR_DO_NOT_DEDUCT = 'do_not_deduct';

    /**
     * ¿Debe descontarse stock al vender este producto?
     * Requiere track_stock=true y behavior=deduct_on_sale.
     */
    public function shouldDeductStockOnSale(): bool
    {
        if (! $this->track_stock) {
            return false;
        }

        // Los productos tipo kit no descuentan stock propio: sus componentes se descuentan
        // (ferretería al vender; cafetería al entregar a cocina).
        if ($this->is_kit) {
            return false;
        }

        $behavior = $this->inventory_behavior ?? InventoryBehavior::DeductOnSale;

        if ($behavior instanceof InventoryBehavior) {
            return $behavior->shouldDeductOnSale();
        }

        return (string) $behavior === self::BEHAVIOR_DEDUCT_ON_SALE;
    }

    public function isOutOfStock(): bool
    {
        if (! $this->track_stock) {
            return false;
        }

        return (float) $this->stock <= 0;
    }

    public function isLowStock(): bool
    {
        if (! $this->track_stock) {
            return false;
        }

        $stock = (float) $this->stock;
        $min = (float) $this->min_stock;

        return $stock > 0 && $stock <= $min;
    }

    /**
     * Alias legible (plan de inventario).
     */
    public function hasLowStock(): bool
    {
        return $this->isLowStock();
    }

    /**
     * normal | low | out | untracked
     */
    public function stockStatus(): string
    {
        if (! $this->track_stock) {
            return 'untracked';
        }
        if ($this->isOutOfStock()) {
            return 'out';
        }
        if ($this->isLowStock()) {
            return 'low';
        }

        return 'normal';
    }

    public function stockStatusLabel(): string
    {
        return match ($this->stockStatus()) {
            'out' => 'Agotado',
            'low' => 'Stock bajo',
            'normal' => 'Normal',
            default => 'Sin control',
        };
    }

    public function stockStatusColor(): string
    {
        return match ($this->stockStatus()) {
            'out' => 'danger',
            'low' => 'warning',
            'normal' => 'success',
            default => 'gray',
        };
    }

    public function imageUrl(): ?string
    {
        if (blank($this->image)) {
            return null;
        }

        if (str_starts_with((string) $this->image, 'http')) {
            return $this->image;
        }

        return Storage::disk('public')->url($this->image);
    }

    public function scopeActive(Builder $query): Builder
    {
        return $query->where('is_active', true);
    }

    public function scopeLowStock(Builder $query): Builder
    {
        return $query
            ->where('track_stock', true)
            ->whereColumn('stock', '<=', 'min_stock');
    }

    // ==================== LOTES (FARMACIA) ====================

    /**
     * Lotes del producto (uso principal: farmacia).
     */
    public function batches(): HasMany
    {
        return $this->hasMany(ProductBatch::class);
    }

    /**
     * ¿El producto opera con lotes?
     * Regla actual: si el producto tiene al menos un lote registrado.
     */
    public function usesBatches(): bool
    {
        return $this->batches()->exists();
    }

    /**
     * Suma disponible en lotes vendibles (no bloqueados, no vencidos,
     * y opcionalmente excluyendo próximos a vencer).
     */
    public function sellableBatchStock(int $blockNearDays = 0): float
    {
        return (float) $this->batches()
            ->sellable($blockNearDays)
            ->sum('quantity');
    }

    /**
     * ¿Tiene stock vendible efectivo?
     */
    public function hasSellableStock(int $blockNearDays = 0): bool
    {
        if ($this->usesBatches()) {
            return $this->sellableBatchStock($blockNearDays) > 0;
        }

        // Sin lotes: si expira, revisar la fecha del producto.
        if ($this->expires_at) {
            $today = now()->startOfDay();
            $exp = $this->expires_at->copy()->startOfDay();
            if ($exp->lt($today)) {
                return false;
            }
            if ($blockNearDays > 0 && $today->diffInDays($exp, false) <= $blockNearDays) {
                return false;
            }
        }

        return ! $this->track_stock || (float) $this->stock > 0;
    }

    public function scopeExpiringSoon(Builder $query, int $days = 30): Builder
    {
        return $query
            ->whereNotNull('expires_at')
            ->whereDate('expires_at', '>=', now()->toDateString())
            ->whereDate('expires_at', '<=', now()->addDays($days)->toDateString())
            ->orderBy('expires_at');
    }

    /**
     * Catálogo maestro de unidades de medida. Fuente única de verdad.
     *
     * @return array<string, string>
     */
    public static function allUnits(): array
    {
        return [
            'unidad'  => 'Unidad',
            'caja'    => 'Caja',
            'blister' => 'Blíster',
            'frasco'  => 'Frasco',
            'ampolla' => 'Ampolla',
            'tubo'    => 'Tubo',
            'ml'      => 'Mililitro (ml)',
            'libra'   => 'Libra',
            'kg'      => 'Kilogramo',
            'galon'   => 'Galón',
            'litro'   => 'Litro',
            'saco'    => 'Saco',
            'docena'  => 'Docena',
            'paquete' => 'Paquete',
            'metro'   => 'Metro',
            'yarda'   => 'Yarda',
        ];
    }

    /**
     * Códigos de unidades permitidos por tipo de negocio.
     * Editar aquí para agregar/quitar unidades sin tocar formularios.
     *
     * @return list<string>
     */
    public static function unitsForType(?\App\Enums\BusinessType $type): array
    {
        return match ($type) {
            \App\Enums\BusinessType::Farmacia => [
                'unidad', 'caja', 'blister', 'frasco', 'ampolla', 'tubo', 'ml', 'paquete',
            ],
            \App\Enums\BusinessType::Colmado => [
                'unidad', 'caja', 'libra', 'kg', 'galon', 'litro', 'saco', 'docena', 'paquete',
            ],
            \App\Enums\BusinessType::Ferreteria => [
                'unidad', 'caja', 'metro', 'yarda', 'galon', 'litro', 'saco', 'paquete',
            ],
            \App\Enums\BusinessType::Repuestos => [
                'unidad', 'caja', 'paquete', 'galon', 'litro', 'metro',
            ],
            \App\Enums\BusinessType::Cafeteria => [
                'unidad', 'caja', 'litro', 'ml', 'paquete', 'libra', 'kg',
            ],
            \App\Enums\BusinessType::Tienda => [
                'unidad', 'caja', 'docena', 'paquete',
            ],
            \App\Enums\BusinessType::Distribuidora,
            \App\Enums\BusinessType::Almacen => [
                'unidad', 'caja', 'saco', 'paquete', 'docena', 'kg', 'libra', 'galon', 'litro',
            ],
            // General o sin tipo → todas
            default => array_keys(self::allUnits()),
        };
    }

    /**
     * Opciones para <select> filtradas por tipo de negocio.
     * Sin argumento devuelve todas (útil en reportes globales / tests).
     *
     * @return array<string, string>
     */
    public static function unitOptions(?\App\Enums\BusinessType $type = null): array
    {
        $allowed = $type === null
            ? array_keys(self::allUnits())
            : self::unitsForType($type);

        return array_intersect_key(self::allUnits(), array_flip($allowed));
    }

    protected static function booted(): void
    {
        static::saving(function (Product $p): void {
            // Todo controlado exige receta.
            if (filled($p->controlled_type)) {
                $p->requires_prescription = true;
            }
        });
    }

    // ---- Ferretería ----
    public function variants(): HasMany { return $this->hasMany(ProductVariant::class); }
    public function units(): HasMany { return $this->hasMany(ProductUnit::class); }
    public function locations()
    {
        return $this->belongsToMany(ProductLocation::class, 'product_location_assignments', 'product_id', 'location_id')
            ->withPivot('is_primary')->withTimestamps();
    }
    public function kitComponents(): HasMany { return $this->hasMany(ProductKitItem::class, 'kit_product_id'); }
    public function substitutes(): HasMany { return $this->hasMany(ProductSubstitute::class, 'product_id'); }
    public function serials(): HasMany { return $this->hasMany(ProductSerial::class); }
    public function productSuppliers(): HasMany { return $this->hasMany(ProductSupplier::class); }
}
