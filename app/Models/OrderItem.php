<?php

namespace App\Models;

use App\Traits\BelongsToBusiness;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class OrderItem extends Model
{
    use BelongsToBusiness;
    use HasUuids;

    protected $fillable = [
        // business_id: trait / se hereda del order
        'order_id',
        'product_id',
        'product_name',
        'oem_code',
        'unit',
        'unit_price',
        'quantity',
        'subtotal',
    ];

    protected function casts(): array
    {
        return [
            'unit_price' => 'decimal:2',
            'quantity' => 'decimal:3',
            'subtotal' => 'decimal:2',
        ];
    }

    public function order(): BelongsTo
    {
        return $this->belongsTo(Order::class);
    }

    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class);
    }

    protected static function booted(): void
    {
        static::creating(function (OrderItem $item): void {
            // Si el trait no resolvió tenant, heredar del order
            if (empty($item->business_id) && $item->order_id) {
                $order = Order::withoutBusinessScope()->find($item->order_id);
                if ($order) {
                    $item->business_id = $order->business_id;
                }
            }

            if ($item->product_id && empty($item->product_name)) {
                $product = Product::withoutBusinessScope()->find($item->product_id);
                if ($product) {
                    $item->product_name = $product->name;
                    if (empty($item->unit_price)) {
                        $item->unit_price = $product->price;
                    }
                    if (empty($item->unit)) {
                        $item->unit = $product->unit ?? 'unidad';
                    }
                }
            }
        });

        static::saving(function (OrderItem $item): void {
            $item->subtotal = round(
                (float) $item->unit_price * (float) $item->quantity,
                2
            );
        });

        static::saved(function (OrderItem $item): void {
            $item->order?->recalculateTotals();
        });

        static::deleted(function (OrderItem $item): void {
            $item->order?->recalculateTotals();
        });
    }
}
