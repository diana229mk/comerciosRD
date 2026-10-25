<?php

namespace App\Models;

use App\Traits\BelongsToBusiness;
use App\Traits\ScopedByBranch;
use App\Traits\TracksBranch;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * Lote de producto (uso principal: farmacias).
 * Un producto puede tener N lotes con fecha de vencimiento distinta.
 * La venta debe descontar por FEFO (First Expired First Out).
 */
class ProductBatch extends Model
{
    use BelongsToBusiness;
    use HasFactory;
    use HasUuids;
    use SoftDeletes;
    use ScopedByBranch;
    use TracksBranch;

    protected $table = 'product_batches';

    protected $fillable = [
        'product_id',
        'branch_id',
        'code',
        'quantity',
        'initial_quantity',
        'cost',
        'expires_at',
        'received_at',
        'is_blocked',
        'notes',
        'created_by',
    ];

    protected function casts(): array
    {
        return [
            'quantity' => 'decimal:3',
            'initial_quantity' => 'decimal:3',
            'cost' => 'decimal:2',
            'expires_at' => 'date',
            'received_at' => 'date',
            'is_blocked' => 'boolean',
        ];
    }

    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class);
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function movements(): HasMany
    {
        return $this->hasMany(InventoryMovement::class, 'batch_id');
    }

    /**
     * Asignaciones a líneas de venta (trazabilidad — qué se vendió de este lote).
     */
    public function saleAllocations(): HasMany
    {
        return $this->hasMany(SaleItemBatch::class, 'batch_id');
    }

    // ==================== HELPERS ====================

    public function isExpired(?\DateTimeInterface $reference = null): bool
    {
        if (! $this->expires_at) {
            return false;
        }

        $ref = $reference ? \Carbon\Carbon::instance($reference) : now();

        return $this->expires_at->startOfDay()->lt($ref->startOfDay());
    }

    public function daysToExpire(?\DateTimeInterface $reference = null): ?int
    {
        if (! $this->expires_at) {
            return null;
        }

        $ref = $reference ? \Carbon\Carbon::instance($reference) : now();

        return (int) $ref->startOfDay()->diffInDays($this->expires_at->copy()->startOfDay(), false);
    }

    public function isNearExpiry(int $days): bool
    {
        $d = $this->daysToExpire();

        return $d !== null && $d >= 0 && $d <= $days;
    }

    /**
     * ¿Se puede vender de este lote?
     *  - No bloqueado
     *  - No borrado
     *  - Con cantidad > 0
     *  - No vencido
     *  - Si $blockNearExpiryDays > 0: tampoco puede estar dentro de ese rango
     */
    public function isSellable(int $blockNearExpiryDays = 0): bool
    {
        if ($this->is_blocked || $this->trashed()) {
            return false;
        }

        if ((float) $this->quantity <= 0) {
            return false;
        }

        if ($this->isExpired()) {
            return false;
        }

        if ($blockNearExpiryDays > 0 && $this->isNearExpiry($blockNearExpiryDays)) {
            return false;
        }

        return true;
    }

    // ==================== SCOPES ====================

    public function scopeAvailable(Builder $query): Builder
    {
        return $query->where('is_blocked', false)->where('quantity', '>', 0);
    }

    public function scopeNotExpired(Builder $query): Builder
    {
        return $query->where(function (Builder $q) {
            $q->whereNull('expires_at')
                ->orWhereDate('expires_at', '>=', now()->toDateString());
        });
    }

    public function scopeExpired(Builder $query): Builder
    {
        return $query->whereNotNull('expires_at')
            ->whereDate('expires_at', '<', now()->toDateString());
    }

    public function scopeExpiringSoon(Builder $query, int $days = 30): Builder
    {
        return $query
            ->whereNotNull('expires_at')
            ->whereDate('expires_at', '>=', now()->toDateString())
            ->whereDate('expires_at', '<=', now()->addDays($days)->toDateString());
    }

    /**
     * FEFO: primero los que vencen antes.
     * Los lotes sin expires_at van al final (más flexibles).
     */
    public function scopeFefoOrder(Builder $query): Builder
    {
        return $query
            ->orderByRaw('CASE WHEN expires_at IS NULL THEN 1 ELSE 0 END')
            ->orderBy('expires_at')
            ->orderBy('created_at');
    }

    /**
     * Lotes vendibles para FEFO: available + not expired.
     * Si $blockNearDays > 0, excluye los dentro del rango de proximidad.
     */
    public function scopeSellable(Builder $query, int $blockNearDays = 0): Builder
    {
        $query->available()->notExpired();

        if ($blockNearDays > 0) {
            $query->whereDate('expires_at', '>', now()->addDays($blockNearDays)->toDateString());
        }

        return $query;
    }
}
