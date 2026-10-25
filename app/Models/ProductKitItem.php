<?php

namespace App\Models;

use App\Traits\BelongsToBusiness;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ProductKitItem extends Model
{
    use BelongsToBusiness, HasUuids;

    protected $fillable = ['kit_product_id', 'component_product_id', 'component_variant_id', 'quantity'];
    protected $casts = ['quantity' => 'decimal:3'];

    public function kit(): BelongsTo { return $this->belongsTo(Product::class, 'kit_product_id'); }
    public function component(): BelongsTo { return $this->belongsTo(Product::class, 'component_product_id'); }
    public function variant(): BelongsTo { return $this->belongsTo(ProductVariant::class, 'component_variant_id'); }
}
