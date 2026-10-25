<?php

namespace App\Models;

use App\Traits\BelongsToBusiness;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class ProductVariant extends Model
{
    use BelongsToBusiness, HasUuids;

    protected $fillable = [
        'product_id', 'sku', 'barcode', 'attributes',
        'price', 'cost', 'stock', 'is_active',
    ];
    protected $casts = [
        'attributes' => 'array',
        'price' => 'decimal:2', 'cost' => 'decimal:2', 'stock' => 'decimal:3',
        'is_active' => 'boolean',
    ];

    public function product(): BelongsTo { return $this->belongsTo(Product::class); }

    public function inventoryMovements(): \Illuminate\Database\Eloquent\Relations\HasMany
    {
        return $this->hasMany(InventoryMovement::class, 'variant_id');
    }

    public function saleItems(): HasMany
    {
        return $this->hasMany(SaleItem::class, 'variant_id');
    }

    public function label(): string
    {
        $parts = collect($this->attributes ?? [])->map(fn ($v, $k) => "$k: $v")->all();
        return implode(' · ', $parts) ?: $this->sku;
    }
}
