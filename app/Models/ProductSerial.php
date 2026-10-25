<?php

namespace App\Models;

use App\Traits\BelongsToBusiness;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ProductSerial extends Model
{
    use BelongsToBusiness, HasUuids;

    protected $fillable = [
        'product_id', 'variant_id', 'serial_number', 'status',
        'purchase_id', 'sold_in_sale_id', 'sold_at', 'notes',
    ];
    protected $casts = ['sold_at' => 'datetime'];

    public function product(): BelongsTo { return $this->belongsTo(Product::class); }
    public function variant(): BelongsTo { return $this->belongsTo(ProductVariant::class, 'variant_id'); }
    public function sale(): BelongsTo { return $this->belongsTo(Sale::class, 'sold_in_sale_id'); }
}
