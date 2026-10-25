<?php

namespace App\Models;

use App\Traits\BelongsToBusiness;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class CustomerLoyaltyAccount extends Model
{
    use BelongsToBusiness, HasUuids;

    protected $fillable = [
        'customer_id', 'points', 'wallet_balance', 'tier', 'status', 'last_points_date',
    ];

    protected $casts = [
        'points' => 'integer',
        'wallet_balance' => 'decimal:2',
        'last_points_date' => 'datetime',
    ];

    public function customer(): BelongsTo
    {
        return $this->belongsTo(Customer::class);
    }
}
