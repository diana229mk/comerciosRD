<?php

namespace App\Models;

use App\Traits\BelongsToBusiness;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class SaleReturnOperation extends Model
{
    use BelongsToBusiness;
    use HasUuids;

    protected $fillable = [
        'sale_id',
        'request_key',
        'payload_hash',
        'allocation_quantities',
        'refund_amount',
        'refund_due_amount',
        'tax_amount',
        'inventory_cost',
        'refund_channel',
        'refund_method',
        'refund_status',
        'refund_cash_session_id',
        'refund_settlement_key',
        'refund_settlement_hash',
        'refund_settled_at',
        'refund_note',
        'ecf_requested',
        'ecf_status',
        'ecf_attempts',
        'ecf_available_at',
        'ecf_last_error',
        'electronic_invoice_id',
        'created_by',
    ];

    protected function casts(): array
    {
        return [
            'allocation_quantities' => 'array',
            'refund_amount' => 'decimal:2',
            'refund_due_amount' => 'decimal:2',
            'tax_amount' => 'decimal:2',
            'inventory_cost' => 'decimal:2',
            'ecf_requested' => 'boolean',
            'ecf_attempts' => 'integer',
            'ecf_available_at' => 'datetime',
            'refund_settled_at' => 'datetime',
        ];
    }

    public function sale(): BelongsTo
    {
        return $this->belongsTo(Sale::class);
    }

    public function refundCashSession(): BelongsTo
    {
        return $this->belongsTo(CashSession::class, 'refund_cash_session_id');
    }

    public function electronicInvoice(): BelongsTo
    {
        return $this->belongsTo(ElectronicInvoice::class);
    }

    public function cashMovements(): HasMany
    {
        return $this->hasMany(CashSessionMovement::class, 'source_id', 'id')
            ->where('source_type', 'sale_return_refund');
    }

    public function inventoryMovements(): HasMany
    {
        return $this->hasMany(InventoryMovement::class);
    }
}
