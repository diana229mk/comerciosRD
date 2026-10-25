<?php

namespace App\Models;

use App\Traits\BelongsToBusiness;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class SpecialOrder extends Model
{
    use BelongsToBusiness, HasUuids;

    protected $fillable = [
        'code', 'customer_id', 'supplier_id', 'status',
        'deposit_amount', 'expected_date',
        'purchase_id', 'sale_id', 'created_by', 'notes',
    ];

    protected $casts = [
        'deposit_amount' => 'decimal:2',
        'expected_date' => 'date',
    ];

    public function customer(): BelongsTo { return $this->belongsTo(Customer::class); }
    public function supplier(): BelongsTo { return $this->belongsTo(Supplier::class); }
    public function items(): HasMany { return $this->hasMany(SpecialOrderItem::class); }
}
