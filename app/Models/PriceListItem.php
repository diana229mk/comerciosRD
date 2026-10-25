<?php

namespace App\Models;

use App\Traits\BelongsToBusiness;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class PriceListItem extends Model
{
    use BelongsToBusiness, HasUuids;

    protected $fillable = ['price_list_id', 'product_id', 'variant_id', 'price', 'min_quantity'];
    protected $casts = ['price' => 'decimal:2', 'min_quantity' => 'decimal:3'];

    public function priceList(): BelongsTo { return $this->belongsTo(PriceList::class); }
    public function product(): BelongsTo { return $this->belongsTo(Product::class); }
    public function variant(): BelongsTo { return $this->belongsTo(ProductVariant::class, 'variant_id'); }
}
