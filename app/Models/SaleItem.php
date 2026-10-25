<?php

namespace App\Models;

use App\Traits\BelongsToBusiness;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class SaleItem extends Model
{
    use BelongsToBusiness;
    use HasUuids;

    protected $fillable = [
        // business_id: trait / hereda de Sale
        'sale_id',
        'product_id',
        'prescription_item_id',
        'product_name',
        'sku',
        'unit',
        'unit_price',
        'unit_cost',
        'quantity',
        'subtotal',
        'line_total', // legacy
        'variant_id',
        'unit_id',
        'unit_factor',
        'parent_sale_item_id',
        'kit_line',
    ];

    protected function casts(): array
    {
        return [
            'unit_price' => 'decimal:2',
            'unit_cost' => 'decimal:2',
            'quantity' => 'decimal:3',
            'subtotal' => 'decimal:2',
            'line_total' => 'decimal:2',
            'unit_factor' => 'decimal:6',
            'kit_line' => 'boolean',
        ];
    }

    public function sale(): BelongsTo
    {
        return $this->belongsTo(Sale::class);
    }

    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class);
    }

    public function variant(): BelongsTo
    {
        return $this->belongsTo(ProductVariant::class, 'variant_id');
    }

    /**
     * Lotes de los que se despachó esta línea (trazabilidad).
     */
    public function batchAllocations(): HasMany
    {
        return $this->hasMany(SaleItemBatch::class);
    }

    public function prescriptionItem(): BelongsTo
    {
        return $this->belongsTo(PrescriptionItem::class);
    }

    protected static function booted(): void
    {
        static::creating(function (SaleItem $item): void {
            if (empty($item->business_id) && $item->sale_id) {
                $sale = Sale::withoutBusinessScope()->find($item->sale_id);
                if ($sale) {
                    $item->business_id = $sale->business_id;
                }
            }

            if ($item->product_id && empty($item->product_name)) {
                $product = Product::withoutBusinessScope()->find($item->product_id);
                if ($product) {
                    $item->product_name = $product->name;
                    $item->sku = $item->sku ?: $product->sku;
                    $item->unit = $item->unit ?: ($product->unit ?? 'unidad');
                    if ($item->unit_price === null) {
                        $item->unit_price = $product->price;
                    }
                    if ($item->unit_cost === null) {
                        $item->unit_cost = $product->cost ?? 0;
                    }
                }
            }
        });

        static::saving(function (SaleItem $item): void {
            $subtotal = round((float) $item->unit_price * (float) $item->quantity, 2);
            $item->subtotal = $subtotal;
            $item->line_total = $subtotal;
        });

        static::saved(function (SaleItem $item): void {
            $item->sale?->recalculateTotals();
        });

        static::deleted(function (SaleItem $item): void {
            $item->sale?->recalculateTotals();
        });
    }
}
