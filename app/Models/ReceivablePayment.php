<?php

namespace App\Models;

use App\Traits\BelongsToBusiness;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ReceivablePayment extends Model
{
    use BelongsToBusiness;
    use HasUuids;

    protected $fillable = [
        // business_id: trait
        'receivable_id',
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

    public function receivable(): BelongsTo
    {
        return $this->belongsTo(Receivable::class);
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    protected static function booted(): void
    {
        static::creating(function (ReceivablePayment $payment): void {
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

        static::saved(function (ReceivablePayment $payment): void {
            $payment->receivable?->refreshPaymentStatus();
        });

        static::deleted(function (ReceivablePayment $payment): void {
            $payment->receivable?->refreshPaymentStatus();
        });
    }
}
