<?php

namespace App\Models;

use App\Traits\BelongsToBusiness;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class PriceList extends Model
{
    use BelongsToBusiness, HasUuids;

    protected $fillable = [
        'code', 'name', 'customer_segment', 'is_default', 'is_active',
        'global_markup_pct', 'global_discount_pct',
    ];
    protected $casts = [
        'is_default' => 'boolean', 'is_active' => 'boolean',
        'global_markup_pct' => 'decimal:2', 'global_discount_pct' => 'decimal:2',
    ];

    public function items(): HasMany { return $this->hasMany(PriceListItem::class); }
}
