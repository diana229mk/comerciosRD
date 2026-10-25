<?php

namespace App\Models;

use App\Traits\BelongsToBusiness;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ProductLocationAssignment extends Model
{
    use BelongsToBusiness, HasUuids;

    protected $fillable = ['product_id', 'location_id', 'is_primary'];
    protected $casts = ['is_primary' => 'boolean'];

    public function product(): BelongsTo { return $this->belongsTo(Product::class); }
    public function location(): BelongsTo { return $this->belongsTo(ProductLocation::class, 'location_id'); }
}
