<?php

namespace App\Models;

use App\Traits\BelongsToBusiness;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class GiftCard extends Model
{
    use BelongsToBusiness, HasUuids;

    protected $fillable = [
        'code', 'customer_id', 'amount', 'balance', 'status', 'expires_at', 'notes',
    ];

    protected $casts = [
        'amount' => 'decimal:2',
        'balance' => 'decimal:2',
        'expires_at' => 'date',
    ];

    public function customer(): BelongsTo
    {
        return $this->belongsTo(Customer::class);
    }

    public function redeem(float $amount): bool
    {
        if ($this->status !== 'active' || $amount <= 0 || $this->balance < $amount) {
            return false;
        }

        $this->balance = round($this->balance - $amount, 2);
        if ($this->balance <= 0) {
            $this->status = 'used';
        }

        return $this->save();
    }
}
