<?php

namespace App\Models;

use App\Traits\BelongsToBusiness;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class PayablePayment extends Model
{
    use BelongsToBusiness;
    use HasUuids;

    protected $fillable = [
        // business_id: trait
        'payable_id',
        'amount',
        'payment_method',
        'payment_date',
        'reference',
        'notes',
        'created_by',
    ];

    protected function casts(): array
    {
        return [
            'amount' => 'decimal:2',
            'payment_date' => 'date',
        ];
    }

    public function payable(): BelongsTo
    {
        return $this->belongsTo(Payable::class);
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    protected static function booted(): void
    {
        static::creating(function (PayablePayment $payment): void {
            if (empty($payment->created_by) && auth()->check()) {
                $payment->created_by = auth()->id();
            }

            if (empty($payment->payment_date)) {
                $payment->payment_date = now()->toDateString();
            }

            if (empty($payment->payment_method)) {
                $payment->payment_method = 'efectivo';
            }
        });

        static::saved(function (PayablePayment $payment): void {
            $payment->payable?->refreshPaymentStatus();
        });

        static::deleted(function (PayablePayment $payment): void {
            $payment->payable?->refreshPaymentStatus();
        });
    }
}
