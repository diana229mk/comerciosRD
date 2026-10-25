<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ElectronicInvoiceItem extends Model
{
    use HasUuids;

    protected $fillable = [
        'electronic_invoice_id',
        'product_id',
        'product_name',
        'product_code',
        'quantity',
        'unit_price',
        'discount',
        'tax_rate',
        'tax_amount',
        'subtotal',
        'total',
    ];

    protected function casts(): array
    {
        return [
            'quantity' => 'decimal:3',
            'unit_price' => 'decimal:2',
            'discount' => 'decimal:2',
            'tax_rate' => 'decimal:2',
            'tax_amount' => 'decimal:2',
            'subtotal' => 'decimal:2',
            'total' => 'decimal:2',
        ];
    }

    public function electronicInvoice(): BelongsTo
    {
        return $this->belongsTo(ElectronicInvoice::class);
    }

    /** Alias */
    public function invoice(): BelongsTo
    {
        return $this->electronicInvoice();
    }

    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class);
    }

    /**
     * Recalcula subtotal, ITBIS y total de la línea.
     */
    public function recalculateLine(): void
    {
        $qty = (float) $this->quantity;
        $price = (float) $this->unit_price;
        $discount = (float) $this->discount;
        $rate = (float) ($this->tax_rate ?? 18);

        $base = max(0, round(($qty * $price) - $discount, 2));
        $tax = round($base * ($rate / 100), 2);

        $this->subtotal = $base;
        $this->tax_amount = $tax;
        $this->total = round($base + $tax, 2);
    }

    protected static function booted(): void
    {
        static::saving(function (ElectronicInvoiceItem $item): void {
            // Solo recalcular si no vienen montos explícitos ya definidos
            if ($item->tax_rate === null) {
                $item->tax_rate = 18;
            }

            // Si se están seteando montos a mano (desde EcfService), no pisarlos
            if ($item->isDirty('subtotal') && $item->isDirty('total') && $item->isDirty('tax_amount')) {
                return;
            }

            $item->recalculateLine();
        });
    }
}
