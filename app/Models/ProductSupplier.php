<?php

namespace App\Models;

use App\Traits\BelongsToBusiness;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ProductSupplier extends Model
{
    use BelongsToBusiness, HasUuids;

    protected $fillable = [
        'product_id', 'supplier_id', 'supplier_code',
        'last_cost', 'last_quantity', 'last_purchase_date',
        'lead_time_days', 'is_preferred',
    ];

    protected $casts = [
        'last_cost' => 'decimal:2',
        'last_quantity' => 'decimal:3',
        'last_purchase_date' => 'date',
        'is_preferred' => 'boolean',
    ];

    public function product(): BelongsTo { return $this->belongsTo(Product::class); }
    public function supplier(): BelongsTo { return $this->belongsTo(Supplier::class); }
}
