<?php

namespace App\Models;

use App\Enums\PurchasePaymentStatus;
use App\Enums\PurchaseStatus;
use App\Traits\BelongsToBusiness;
use App\Traits\ScopedByBranch;
use App\Traits\TracksBranch;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

class Purchase extends Model
{
    use BelongsToBusiness;
    use HasUuids;
    use ScopedByBranch;
    use TracksBranch;
    use SoftDeletes;

    protected $fillable = [
        // business_id: lo inyecta BelongsToBusiness (no mass-assignment)
        'supplier_id',
        'supplier_name',
        'purchase_number',
        'invoice_number',
        'status',
        'subtotal',
        'tax',
        'total',
        'payment_status',
        'amount_paid',
        'purchase_date',
        'due_date',
        'received_at',
        'notes',
        'payable_id',
        'created_by',
    ];

    protected function casts(): array
    {
        return [
            'status' => PurchaseStatus::class,
            'payment_status' => PurchasePaymentStatus::class,
            'subtotal' => 'decimal:2',
            'tax' => 'decimal:2',
            'total' => 'decimal:2',
            'amount_paid' => 'decimal:2',
            'purchase_date' => 'date',
            'due_date' => 'date',
            'received_at' => 'datetime',
        ];
    }

    public function items(): HasMany
    {
        return $this->hasMany(PurchaseItem::class);
    }

    public function supplier(): BelongsTo
    {
        return $this->belongsTo(Supplier::class);
    }

    public function payable(): BelongsTo
    {
        return $this->belongsTo(Payable::class);
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function scopeDraft(Builder $query): Builder
    {
        return $query->where('status', PurchaseStatus::Borrador);
    }

    public function scopeReceived(Builder $query): Builder
    {
        return $query->where('status', PurchaseStatus::Recibida);
    }

    public function isReceived(): bool
    {
        $status = $this->status;

        if ($status instanceof PurchaseStatus) {
            return $status === PurchaseStatus::Recibida;
        }

        return (string) $status === PurchaseStatus::Recibida->value;
    }

    public function isDraft(): bool
    {
        $status = $this->status;

        if ($status === null) {
            return true;
        }

        if ($status instanceof PurchaseStatus) {
            return $status === PurchaseStatus::Borrador;
        }

        return (string) $status === PurchaseStatus::Borrador->value;
    }

    public function isAnulada(): bool
    {
        $status = $this->status;

        if ($status instanceof PurchaseStatus) {
            return $status === PurchaseStatus::Anulada;
        }

        return (string) $status === PurchaseStatus::Anulada->value;
    }

    public function statusValue(): string
    {
        $status = $this->status;

        return $status instanceof PurchaseStatus ? $status->value : (string) $status;
    }

    public function paymentStatusValue(): string
    {
        $payment = $this->payment_status;

        return $payment instanceof PurchasePaymentStatus ? $payment->value : (string) $payment;
    }

    /**
     * Número secuencial por negocio: COM-2026-00001
     */
    public static function generatePurchaseNumber(string $businessId): string
    {
        $year = now()->year;
        $prefix = 'COM-'.$year.'-';

        $last = self::withoutBusinessScope()
            ->where('business_id', $businessId)
            ->where('purchase_number', 'like', $prefix.'%')
            ->orderByDesc('purchase_number')
            ->value('purchase_number');

        $next = 1;
        if (is_string($last) && preg_match('/COM-\d{4}-(\d+)/', $last, $matches)) {
            $next = (int) $matches[1] + 1;
        }

        return $prefix.str_pad((string) $next, 5, '0', STR_PAD_LEFT);
    }

    public function recalculateTotals(): void
    {
        $subtotal = (float) $this->items()->sum('subtotal');
        $tax = (float) $this->tax;
        $this->forceFill([
            'subtotal' => round($subtotal, 2),
            'total' => round($subtotal + $tax, 2),
        ])->saveQuietly();
    }

    protected static function booted(): void
    {
        static::creating(function (Purchase $purchase): void {
            if (empty($purchase->created_by) && auth()->check()) {
                $purchase->created_by = auth()->id();
            }

            if (empty($purchase->purchase_date)) {
                $purchase->purchase_date = now()->toDateString();
            }

            if (empty($purchase->status)) {
                $purchase->status = PurchaseStatus::Borrador;
            }

            if (empty($purchase->payment_status)) {
                $purchase->payment_status = PurchasePaymentStatus::Pendiente;
            }

            if (empty($purchase->purchase_number) && $purchase->business_id) {
                $purchase->purchase_number = self::generatePurchaseNumber(
                    (string) $purchase->business_id
                );
            }

            // Nombre del proveedor en snapshot (por si se borra o no está en catálogo)
            if (empty($purchase->supplier_name) && $purchase->supplier_id) {
                $purchase->supplier_name = Supplier::withoutBusinessScope()
                    ->whereKey($purchase->supplier_id)
                    ->value('name');
            }
        });

        static::saving(function (Purchase $purchase): void {
            if ($purchase->isDirty('supplier_id') && $purchase->supplier_id) {
                $name = Supplier::withoutBusinessScope()
                    ->whereKey($purchase->supplier_id)
                    ->value('name');
                if ($name) {
                    $purchase->supplier_name = $name;
                }
            }
        });
    }
}
