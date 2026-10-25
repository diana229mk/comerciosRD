<?php

namespace App\Models;

use App\Enums\OrderStatus;
use App\Enums\OrderType;
use App\Traits\BelongsToBusiness;
use App\Traits\ScopedByBranch;
use App\Traits\TracksBranch;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

class Order extends Model
{
    use BelongsToBusiness;
    use HasUuids;
    use ScopedByBranch;
    use TracksBranch;
    use SoftDeletes;

    protected $fillable = [
        // business_id lo inyecta BelongsToBusiness (no fillable)
        'order_number',
        'quote_number',
        'order_type',
        'customer_name',
        'customer_id',
        'status',
        'valid_until',
        'subtotal',
        'tax',
        'total',
        'notes',
        'created_by',
        'converted_sale_id',
        'ready_at',
        'completed_at',
        'kitchen_issued_at',
    ];

    protected function casts(): array
    {
        return [
            'order_type' => OrderType::class,
            'valid_until' => 'date',
            'subtotal' => 'decimal:2',
            'tax' => 'decimal:2',
            'total' => 'decimal:2',
            'ready_at' => 'datetime',
            'completed_at' => 'datetime',
            'kitchen_issued_at' => 'datetime',
        ];
    }

    // ==================== RELACIONES ====================

    public function items(): HasMany
    {
        return $this->hasMany(OrderItem::class);
    }

    public function customer(): BelongsTo
    {
        return $this->belongsTo(Customer::class);
    }

    public function convertedSale(): BelongsTo
    {
        return $this->belongsTo(Sale::class, 'converted_sale_id');
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    // ==================== SCOPES (dashboard) ====================

    public function scopeOpen(Builder $query): Builder
    {
        return $query->whereIn('status', array_map(
            fn (OrderStatus $s) => $s->value,
            OrderStatus::openStatuses()
        ));
    }

    public function scopeOfType(Builder $query, OrderType|string $type): Builder
    {
        $value = $type instanceof OrderType ? $type->value : $type;

        return $query->where('order_type', $value);
    }

    // ==================== HELPERS ====================

    /**
     * Genera el número de orden secuencial por negocio.
     * Formato: ORD-2026-00001
     */
    public static function generateOrderNumber(string $businessId): string
    {
        $year = now()->year;

        $lastOrder = self::withoutBusinessScope()
            ->where('business_id', $businessId)
            ->whereYear('created_at', $year)
            ->orderByDesc('order_number')
            ->first();

        $nextNumber = 1;

        if ($lastOrder && preg_match('/ORD-\d{4}-(\d+)/', $lastOrder->order_number, $matches)) {
            $nextNumber = (int) $matches[1] + 1;
        }

        return 'ORD-'.$year.'-'.str_pad((string) $nextNumber, 5, '0', STR_PAD_LEFT);
    }

    public static function quoteStatusOptions(): array
    {
        return [
            'draft' => 'Borrador',
            'sent' => 'Enviada',
            'accepted' => 'Aceptada',
            'converted' => 'Convertida',
            'expired' => 'Vencida',
            'cancelled' => 'Cancelada',
        ];
    }

    public function getQuoteNumberAttribute(): string
    {
        return (string) ($this->attributes['quote_number'] ?? $this->attributes['order_number'] ?? '');
    }

    public function setQuoteNumberAttribute($value): void
    {
        $this->attributes['quote_number'] = $value;
    }

    /**
     * Alias usado por dashboard / quick actions.
     */
    public static function nextNumber(string $businessId): string
    {
        return static::generateOrderNumber($businessId);
    }

    public function displayLabel(): string
    {
        $type = $this->order_type instanceof OrderType
            ? $this->order_type
            : OrderType::tryFrom((string) $this->order_type);

        if ($type === OrderType::PorNombre) {
            return $this->customer_name
                ?: ($this->customer?->name ?? $this->order_number);
        }

        return $this->order_number;
    }

    public function typeLabel(): string
    {
        $type = $this->order_type instanceof OrderType
            ? $this->order_type
            : OrderType::tryFrom((string) $this->order_type);

        return $type?->label() ?? (string) $this->order_type;
    }

    public function statusLabel(): string
    {
        $status = $this->status instanceof OrderStatus
            ? $this->status
            : OrderStatus::tryFrom((string) $this->status);

        return $status?->label() ?? (string) $this->status;
    }

    public function recalculateTotals(): void
    {
        $subtotal = (float) $this->items()->sum('subtotal');
        $tax = round($subtotal * 0.18, 2); // ITBIS RD
        $total = round($subtotal + $tax, 2);

        $this->forceFill([
            'subtotal' => $subtotal,
            'tax' => $tax,
            'total' => $total,
        ])->saveQuietly();
    }

    protected static function booted(): void
    {
        static::creating(function (Order $order): void {
            // business_id ya forzado por BelongsToBusiness si hay tenant
            $businessId = $order->business_id
                ?: static::resolveCurrentBusinessId();

            if (empty($order->order_number) && $businessId) {
                $order->order_number = self::generateOrderNumber((string) $businessId);
            }

            if (empty($order->created_by) && auth()->check()) {
                $order->created_by = auth()->id();
            }

            if (empty($order->status)) {
                $order->status = OrderStatus::Pendiente->value;
            }

            if (empty($order->order_type)) {
                $order->order_type = OrderType::PorNumero;
            }

            if (empty($order->quote_number) && $order->business?->business_type === 'repuestos') {
                $order->quote_number = 'COT-'.now()->format('Ymd').'-'.str_pad((string) ((self::withoutBusinessScope()->where('business_id', $order->business_id)->whereDate('created_at', today())->count()) + 1), 4, '0', STR_PAD_LEFT);
            }
        });
    }
}
