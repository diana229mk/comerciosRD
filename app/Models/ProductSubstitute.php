<?php

namespace App\Models;

use App\Traits\BelongsToBusiness;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ProductSubstitute extends Model
{
    use BelongsToBusiness, HasUuids;

    protected $fillable = ['product_id', 'substitute_id', 'priority', 'reason'];

    public function product(): BelongsTo { return $this->belongsTo(Product::class, 'product_id'); }
    public function substitute(): BelongsTo { return $this->belongsTo(Product::class, 'substitute_id'); }
}
