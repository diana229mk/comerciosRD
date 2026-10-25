<?php

namespace App\Models;

use App\Traits\BelongsToBusiness;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Renglón de una receta médica. Puede dispensarse parcial o totalmente
 * a través de una o varias ventas (SaleItem.prescription_item_id).
 */
class PrescriptionItem extends Model
{
    use BelongsToBusiness;
    use HasUuids;

    protected $fillable = [
        'prescription_id',
        'product_id',
        'product_name',
        'quantity_prescribed',
        'quantity_dispensed',
        'dosage',
        'duration',
        'instructions',
    ];

    protected function casts(): array
    {
        return [
            'quantity_prescribed' => 'decimal:3',
            'quantity_dispensed' => 'decimal:3',
        ];
    }

    public function prescription(): BelongsTo
    {
        return $this->belongsTo(Prescription::class);
    }

    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class);
    }

    public function saleItems(): HasMany
    {
        return $this->hasMany(SaleItem::class, 'prescription_item_id');
    }

    public function remainingQuantity(): float
    {
        return max(0.0, (float) $this->quantity_prescribed - (float) $this->quantity_dispensed);
    }

    public function isFullyDispensed(): bool
    {
        return $this->remainingQuantity() <= 0.0001;
    }

    protected static function booted(): void
    {
        static::creating(function (PrescriptionItem $item): void {
            if (empty($item->business_id) && $item->prescription_id) {
                $rx = Prescription::withoutBusinessScope()->find($item->prescription_id);
                if ($rx) {
                    $item->business_id = $rx->business_id;
                }
            }

            if ($item->product_id && empty($item->product_name)) {
                $product = Product::withoutBusinessScope()->find($item->product_id);
                if ($product) {
                    $item->product_name = $product->name;
                }
            }
        });
    }
}
