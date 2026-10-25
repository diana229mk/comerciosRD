<?php

namespace App\Models;

use App\Traits\BelongsToBusiness;
use App\Traits\ScopedByBranch;
use App\Traits\TracksBranch;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class CashSession extends Model
{
    use BelongsToBusiness;
    use HasUuids;
    use ScopedByBranch;
    use TracksBranch;

    public const STATUS_OPEN = 'open';

    public const STATUS_CLOSED = 'closed';

    protected $fillable = [
        // business_id: trait
        'user_id',
        'closed_by',
        'opening_amount',
        'closing_amount',
        'expected_amount',
        'difference',
        'total_sales_cash',
        'total_expenses_cash',
        'status',
        'opening_notes',
        'closing_notes',
        'opened_at',
        'closed_at',
    ];

    protected function casts(): array
    {
        return [
            'opening_amount' => 'decimal:2',
            'closing_amount' => 'decimal:2',
            'expected_amount' => 'decimal:2',
            'difference' => 'decimal:2',
            'total_sales_cash' => 'decimal:2',
            'total_expenses_cash' => 'decimal:2',
            'opened_at' => 'datetime',
            'closed_at' => 'datetime',
        ];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function closedByUser(): BelongsTo
    {
        return $this->belongsTo(User::class, 'closed_by');
    }

    public function isOpen(): bool
    {
        return $this->status === self::STATUS_OPEN;
    }

    public function isClosed(): bool
    {
        return $this->status === self::STATUS_CLOSED;
    }

    public function scopeOpen(Builder $query): Builder
    {
        return $query->where('status', self::STATUS_OPEN);
    }

    /**
     * Calcula el monto esperado: apertura + ventas efectivo - gastos efectivo.
     */
    public function calculateExpectedAmount(): float
    {
        return round(
            (float) $this->opening_amount
            + (float) $this->total_sales_cash
            - (float) $this->total_expenses_cash,
            2
        );
    }
}
