<?php

namespace App\Models;

use App\Traits\BelongsToBusiness;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class QuoteItem extends Model
{
    use BelongsToBusiness, HasUuids;

    protected $fillable = [
        'quote_id', 'product_id', 'variant_id', 'product_name', 'oem_code',
        'unit_id', 'unit_code', 'quantity', 'unit_price', 'subtotal',
    ];
    protected $casts = [
        'quantity' => 'decimal:3', 'unit_price' => 'decimal:2', 'subtotal' => 'decimal:2',
    ];

    public function quote(): BelongsTo { return $this->belongsTo(Quote::class); }
    public function product(): BelongsTo { return $this->belongsTo(Product::class); }
    public function variant(): BelongsTo { return $this->belongsTo(ProductVariant::class, 'variant_id'); }
    public function unit(): BelongsTo { return $this->belongsTo(ProductUnit::class, 'unit_id'); }
}
