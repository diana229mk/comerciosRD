<?php

namespace App\Models;

use App\Traits\BelongsToBusiness;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class SpecialOrderItem extends Model
{
    use BelongsToBusiness, HasUuids;

    protected $fillable = [
        'special_order_id', 'product_id', 'description',
        'quantity', 'quoted_cost', 'quoted_price', 'status', 'notes',
    ];

    protected $casts = [
        'quantity' => 'decimal:3',
        'quoted_cost' => 'decimal:2',
        'quoted_price' => 'decimal:2',
    ];

    public function specialOrder(): BelongsTo { return $this->belongsTo(SpecialOrder::class); }
    public function product(): BelongsTo { return $this->belongsTo(Product::class); }
}
