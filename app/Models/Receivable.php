<?php

namespace App\Models;

use App\Services\CashSessionService;
use App\Traits\BelongsToBusiness;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

class Receivable extends Model
{
    use BelongsToBusiness;
    use HasUuids;
    use SoftDeletes;

    protected $fillable = [
        // business_id: trait
        'customer_id',
        'customer_name',
        'reference',
        'description',
        'amount',
        'paid_amount',
        'balance',
        'due_date',
        'status',
        'notes',
        'created_by',
    ];

    protected function casts(): array
    {
        return [
            'amount' => 'decimal:2',
            'paid_amount' => 'decimal:2',
            'balance' => 'decimal:2',
            'due_date' => 'date',
        ];
    }

    public function customer(): BelongsTo
    {
        return $this->belongsTo(Customer::class);
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function payments(): HasMany
    {
        return $this->hasMany(ReceivablePayment::class)
            ->orderByDesc('payment_date')
            ->orderByDesc('created_at');
    }

    public function scopeOpen(Builder $query): Builder
    {
        return $query->whereIn('status', ['pendiente', 'parcial', 'vencida']);
    }

    public function scopeDueSoon(Builder $query, int $days = 7): Builder
    {
        return $query
            ->open()
            ->whereDate('due_date', '<=', now()->addDays($days)->toDateString())
            ->orderBy('due_date');
    }

    public function isPaid(): bool
    {
        return $this->status === 'pagada' || (float) $this->balance <= 0;
    }

    /**
     * Recalcula paid_amount, balance y status según los cobros registrados.
     */
    public function refreshPaymentStatus(): void
    {
        $paid = round((float) $this->payments()->sum('amount'), 2);
        $amount = round((float) $this->amount, 2);
        $balance = round(max(0, $amount - $paid), 2);

        $status = match (true) {
            $balance <= 0 => 'pagada',
            $paid > 0 => 'parcial',
            $this->due_date && $this->due_date->toDateString() < now()->toDateString() => 'vencida',
            default => 'pendiente',
        };

        // Si hay saldo y está vencida, priorizar vencida sobre pendiente
        if ($balance > 0 && $this->due_date && $this->due_date->isPast() && $status === 'pendiente') {
            $status = 'vencida';
        }

        $this->forceFill([
            'paid_amount' => $paid,
            'balance' => $balance,
            'status' => $status,
        ])->saveQuietly();
    }

    /**
     * Registra un cobro, actualiza estado y, si es efectivo, suma a la caja abierta.
     */
    public function registerPayment(
        float $amount,
        string $paymentMethod = 'efectivo',
        ?string $paymentDate = null,
        ?string $reference = null,
        ?string $notes = null,
        bool $updateCashSession = true,
    ): ReceivablePayment {
        $amount = round($amount, 2);
        if ($amount <= 0) {
            throw new \InvalidArgumentException('El monto del cobro debe ser mayor que cero.');
        }

        $max = round((float) $this->balance, 2);
        if ($max <= 0) {
            throw new \RuntimeException('Esta cuenta ya está cobrada.');
        }

        if ($amount > $max + 0.001) {
            throw new \InvalidArgumentException(
                'El cobro (RD$ '.number_format($amount, 2).') supera el saldo pendiente (RD$ '.number_format($max, 2).').'
            );
        }

        $payment = new ReceivablePayment([
            'receivable_id' => $this->id,
            'amount' => $amount,
            'payment_method' => $paymentMethod,
            'payment_date' => $paymentDate ?: now()->toDateString(),
            'reference' => $reference,
            'notes' => $notes,
            'created_by' => auth()->id(),
        ]);
        $payment->business_id = $this->business_id;
        $payment->save();

        if ($updateCashSession && in_array(strtolower($paymentMethod), ['efectivo', 'cash'], true)) {
            app(CashSessionService::class)->registerCashAmount(
                (string) $this->business_id,
                $amount,
                now(),
                auth()->id() ? (string) auth()->id() : null,
            );
        }

        $this->refresh();

        return $payment;
    }

    protected static function booted(): void
    {
        static::creating(function (Receivable $receivable): void {
            if (empty($receivable->created_by) && auth()->check()) {
                $receivable->created_by = auth()->id();
            }

            if ($receivable->paid_amount === null) {
                $receivable->paid_amount = 0;
            }
        });

        static::saving(function (Receivable $receivable): void {
            $amount = (float) $receivable->amount;
            $paid = (float) $receivable->paid_amount;
            $receivable->balance = round(max(0, $amount - $paid), 2);

            if ($receivable->balance <= 0) {
                $receivable->status = 'pagada';
                $receivable->balance = 0;
            } elseif ($paid > 0) {
                $receivable->status = 'parcial';
            } elseif (
                $receivable->due_date
                && $receivable->due_date->toDateString() < now()->toDateString()
            ) {
                $receivable->status = 'vencida';
            } elseif (empty($receivable->status) || $receivable->status === 'pagada') {
                $receivable->status = 'pendiente';
            }
        });
    }
}
