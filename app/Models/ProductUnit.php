<?php

namespace App\Models;

use App\Traits\BelongsToBusiness;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ProductUnit extends Model
{
    use BelongsToBusiness, HasUuids;

    protected $fillable = [
        'product_id', 'unit_code', 'label', 'factor', 'price',
        'barcode', 'is_base', 'is_active',
    ];
    protected $casts = [
        'factor' => 'decimal:6', 'price' => 'decimal:2',
        'is_base' => 'boolean', 'is_active' => 'boolean',
    ];

    public function product(): BelongsTo { return $this->belongsTo(Product::class); }
}
