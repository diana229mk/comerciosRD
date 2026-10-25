<?php

namespace App\Models;

use App\Traits\BelongsToBusiness;
use App\Traits\TracksBranch;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

class StockCount extends Model
{
    use BelongsToBusiness;
    use HasUuids;
    use SoftDeletes;
    use TracksBranch;

    public const STATUS_DRAFT = 'draft';
    public const STATUS_IN_PROGRESS = 'in_progress';
    public const STATUS_COMPLETED = 'completed';
    public const STATUS_CANCELLED = 'cancelled';

    public const SCOPE_FULL = 'full';
    public const SCOPE_CATEGORY = 'category';
    public const SCOPE_SELECTION = 'selection';

    protected $fillable = [
        'code', 'status', 'scope', 'scope_filters',
        'expected_at', 'counted_at', 'completed_at', 'cancelled_at',
        'started_by', 'closed_by',
        'total_items', 'items_with_difference',
        'total_difference_qty', 'total_cost_impact', 'notes',
    ];

    protected $casts = [
        'scope_filters' => 'array',
        'expected_at' => 'datetime',
        'counted_at' => 'datetime',
        'completed_at' => 'datetime',
        'cancelled_at' => 'datetime',
        'total_difference_qty' => 'decimal:3',
        'total_cost_impact' => 'decimal:2',
    ];

    public function items(): HasMany
    {
        return $this->hasMany(StockCountItem::class);
    }

    public function starter(): BelongsTo
    {
        return $this->belongsTo(User::class, 'started_by');
    }

    public function closer(): BelongsTo
    {
        return $this->belongsTo(User::class, 'closed_by');
    }

    public function isEditable(): bool
    {
        return in_array($this->status, [self::STATUS_DRAFT, self::STATUS_IN_PROGRESS], true);
    }

    public function statusLabel(): string
    {
        return match ($this->status) {
            self::STATUS_DRAFT => 'Borrador',
            self::STATUS_IN_PROGRESS => 'En captura',
            self::STATUS_COMPLETED => 'Completado',
            self::STATUS_CANCELLED => 'Cancelado',
            default => $this->status,
        };
    }
}
