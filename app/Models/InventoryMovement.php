<?php

namespace App\Models;

use App\Enums\InventoryMovementType;
use App\Traits\BelongsToBusiness;
use App\Traits\ScopedByBranch;
use App\Traits\TracksBranch;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\MorphTo;

class InventoryMovement extends Model
{
    use BelongsToBusiness;
    use HasUuids;
    use ScopedByBranch;
    use TracksBranch;

    // ==================== TIPOS DE MOVIMIENTO ====================

    public const TYPE_PURCHASE = 'purchase';           // Entrada por compra

    public const TYPE_SALE = 'sale';                   // Salida por venta

    public const TYPE_ADJUSTMENT_IN = 'adjustment_in'; // Ajuste positivo

    public const TYPE_ADJUSTMENT_OUT = 'adjustment_out'; // Ajuste negativo

    public const TYPE_RETURN_IN = 'return_in';         // Devolución de cliente (entrada)

    public const TYPE_RETURN_OUT = 'return_out';       // Devolución a proveedor (salida)

    /** @var list<string> */
    public const ENTRY_TYPES = [
        self::TYPE_PURCHASE,
        self::TYPE_ADJUSTMENT_IN,
        self::TYPE_RETURN_IN,
    ];

    /** @var list<string> */
    public const EXIT_TYPES = [
        self::TYPE_SALE,
        self::TYPE_ADJUSTMENT_OUT,
        self::TYPE_RETURN_OUT,
    ];

    protected $fillable = [
        // business_id: lo inyecta BelongsToBusiness
        'product_id',
        'variant_id',
        'type',
        'quantity',
        'stock_after',
        'unit_cost',
        'reason',
        'reference_id',
        'reference_type',
        'created_by',
    ];

    protected function casts(): array
    {
        return [
            'type' => InventoryMovementType::class,
            'quantity' => 'decimal:3',
            'stock_after' => 'decimal:3',
            'unit_cost' => 'decimal:2',
        ];
    }

    // ==================== RELACIONES ====================

    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class);
    }

    public function batch(): BelongsTo
    {
        return $this->belongsTo(ProductBatch::class, 'batch_id');
    }

    public function variant(): BelongsTo
    {
        return $this->belongsTo(ProductVariant::class, 'variant_id');
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function reference(): MorphTo
    {
        return $this->morphTo(__FUNCTION__, 'reference_type', 'reference_id');
    }

    // ==================== HELPERS ====================

    /**
     * Verifica si el movimiento es de entrada (aumenta el stock).
     */
    public function isEntry(): bool
    {
        $type = $this->typeValue();

        return in_array($type, self::ENTRY_TYPES, true);
    }

    /**
     * Verifica si el movimiento es de salida (disminuye el stock).
     */
    public function isExit(): bool
    {
        $type = $this->typeValue();

        return in_array($type, self::EXIT_TYPES, true);
    }

    /**
     * Delta de stock de este movimiento (+ entrada / − salida).
     */
    public function stockDelta(): float
    {
        $qty = abs((float) $this->quantity);

        return $this->isEntry() ? $qty : -$qty;
    }

    public function typeValue(): string
    {
        if ($this->type instanceof InventoryMovementType) {
            return $this->type->value;
        }

        return (string) $this->type;
    }

    public function scopeForProduct(Builder $query, string $productId): Builder
    {
        return $query->where('product_id', $productId);
    }

    public function scopeOfType(Builder $query, InventoryMovementType|string $type): Builder
    {
        $value = $type instanceof InventoryMovementType ? $type->value : $type;

        return $query->where('type', $value);
    }

    public function scopeEntries(Builder $query): Builder
    {
        return $query->whereIn('type', self::ENTRY_TYPES);
    }

    public function scopeExits(Builder $query): Builder
    {
        return $query->whereIn('type', self::EXIT_TYPES);
    }

    protected static function booted(): void
    {
        static::creating(function (InventoryMovement $movement): void {
            if (empty($movement->created_by) && auth()->check()) {
                $movement->created_by = auth()->id();
            }

            // Cantidad siempre positiva; el tipo define el sentido
            $movement->quantity = abs((float) $movement->quantity);
        });
    }
}
