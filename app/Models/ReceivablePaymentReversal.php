<?php

namespace App\Models;

use App\Traits\BelongsToBusiness;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ReceivablePaymentReversal extends Model
{
    use BelongsToBusiness;
    use HasUuids;

    protected $fillable = [
        'receivable_payment_id',
        'cash_session_id',
        'request_key',
        'payload_hash',
        'reason',
        'created_by',
    ];

    public function payment(): BelongsTo
    {
        return $this->belongsTo(ReceivablePayment::class, 'receivable_payment_id');
    }

    public function cashSession(): BelongsTo
    {
        return $this->belongsTo(CashSession::class);
    }
}
