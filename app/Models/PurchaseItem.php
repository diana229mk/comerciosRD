<?php

namespace App\Models;

use App\Traits\BelongsToBusiness;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class PurchaseItem extends Model
{
    use BelongsToBusiness;
    use HasUuids;

    protected $fillable = [
        // business_id: trait / se hereda de purchase
        'purchase_id',
        'product_id',
        'product_name',
        'unit_cost',
        'quantity',
        'received_quantity',
        'subtotal',
        'unit',
        'batch_code',
        'expires_at',
        'batch_id',
    ];

    protected function casts(): array
    {
        return [
            'unit_cost' => 'decimal:2',
            'quantity' => 'decimal:3',
            'received_quantity' => 'decimal:3',
            'subtotal' => 'decimal:2',
            'expires_at' => 'date',
        ];
    }

    public function purchase(): BelongsTo
    {
        return $this->belongsTo(Purchase::class);
    }

    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class);
    }

    protected static function booted(): void
    {
        static::creating(function (PurchaseItem $item): void {
            if (empty($item->business_id) && $item->purchase_id) {
                $purchase = Purchase::withoutBusinessScope()->find($item->purchase_id);
                if ($purchase) {
                    $item->business_id = $purchase->business_id;
                }
            }

            if ($item->product_id && empty($item->product_name)) {
                $product = Product::withoutBusinessScope()->find($item->product_id);
                if ($product) {
                    $item->product_name = $product->name;
                    if ($item->unit_cost === null || $item->unit_cost === '') {
                        $item->unit_cost = $product->cost ?? 0;
                    }
                    if (empty($item->unit)) {
                        $item->unit = $product->unit ?? 'unidad';
                    }
                }
            }
        });

        static::saving(function (PurchaseItem $item): void {
            $item->subtotal = round(
                (float) $item->unit_cost * (float) $item->quantity,
                2
            );
        });

        static::saved(function (PurchaseItem $item): void {
            $item->purchase?->recalculateTotals();
        });

        static::deleted(function (PurchaseItem $item): void {
            $item->purchase?->recalculateTotals();
        });
    }
}
