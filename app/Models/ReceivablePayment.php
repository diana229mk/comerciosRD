<?php

namespace App\Models;

use App\Traits\BelongsToBusiness;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasOne;
use RuntimeException;

class ReceivablePayment extends Model
{
    use BelongsToBusiness;
    use HasUuids;

    protected $fillable = [
        // business_id: trait
        'receivable_id',
        'cash_session_id',
        'request_key',
        'payload_hash',
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

    public function reversal(): HasOne
    {
        return $this->hasOne(ReceivablePaymentReversal::class);
    }

    public function cashSession(): BelongsTo
    {
        return $this->belongsTo(CashSession::class);
    }

    public function save(array $options = [])
    {
        throw new RuntimeException('Los cobros de CxC solo pueden registrarse mediante ReceivablePaymentService.');
    }

    public function delete()
    {
        throw new RuntimeException('Los cobros de CxC no se eliminan; deben anularse mediante ReceivablePaymentService.');
    }

    protected static function booted(): void
    {
        static::updating(function (): never {
            throw new RuntimeException('Los cobros de CxC son inmutables.');
        });

        static::deleting(function (): never {
            throw new RuntimeException('Los cobros de CxC son inmutables; registra una anulación.');
        });
    }
}
