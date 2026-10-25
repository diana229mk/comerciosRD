<?php

namespace App\Models;

use App\Traits\BelongsToBusiness;
use Filament\Facades\Filament;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

class Payable extends Model
{
    use BelongsToBusiness;
    use HasUuids;
    use SoftDeletes;

    protected $fillable = [
        // business_id: trait
        'supplier_id',
        'supplier_name',
        'reference',
        'description',
        'amount',
        'paid_amount',
        'balance',
        'due_date',
        'status',
        'notes',
        'invoice_file',
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

    public function supplier(): BelongsTo
    {
        return $this->belongsTo(Supplier::class);
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function payments(): HasMany
    {
        return $this->hasMany(PayablePayment::class)->orderByDesc('payment_date')->orderByDesc('created_at');
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
     * Recalcula paid_amount, balance y status según los pagos registrados.
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

        $this->forceFill([
            'paid_amount' => $paid,
            'balance' => $balance,
            'status' => $status,
        ])->saveQuietly();
    }

    /**
     * Registra un pago y actualiza el estado de la cuenta.
     */
    public function registerPayment(
        float $amount,
        string $paymentMethod = 'efectivo',
        ?string $paymentDate = null,
        ?string $reference = null,
        ?string $notes = null,
    ): PayablePayment {
        $amount = round($amount, 2);
        if ($amount <= 0) {
            throw new \InvalidArgumentException('El monto del pago debe ser mayor que cero.');
        }

        $max = round((float) $this->balance, 2);
        if ($max <= 0) {
            throw new \RuntimeException('Esta cuenta ya está pagada.');
        }

        if ($amount > $max + 0.001) {
            throw new \InvalidArgumentException(
                'El pago (RD$ '.number_format($amount, 2).') supera el saldo pendiente (RD$ '.number_format($max, 2).').'
            );
        }

        $payment = new PayablePayment([
            'payable_id' => $this->id,
            'amount' => $amount,
            'payment_method' => $paymentMethod,
            'payment_date' => $paymentDate ?: now()->toDateString(),
            'reference' => $reference,
            'notes' => $notes,
            'created_by' => auth()->id(),
        ]);
        $payment->business_id = $this->business_id;
        $payment->save();

        $this->refresh();

        return $payment;
    }

    public function invoiceUrl(): ?string
    {
        $tenant = Filament::getTenant();
        if (! $this->invoice_file || ! $tenant || (string) $tenant->getKey() !== (string) $this->business_id) {
            return null;
        }

        return route('private-documents.payables.invoice', [
            'tenant' => $tenant->slug,
            'payable' => $this->getKey(),
        ]);
    }

    protected static function booted(): void
    {
        static::creating(function (Payable $payable): void {
            if (empty($payable->created_by) && auth()->check()) {
                $payable->created_by = auth()->id();
            }

            if ($payable->paid_amount === null) {
                $payable->paid_amount = 0;
            }
        });

        static::saving(function (Payable $payable): void {
            // Si hay pagos en tabla, paid_amount se sincroniza con refreshPaymentStatus.
            // Aquí recalculamos balance/status por si se edita amount o paid_amount manualmente.
            $amount = (float) $payable->amount;
            $paid = (float) $payable->paid_amount;
            $payable->balance = round(max(0, $amount - $paid), 2);

            if ($payable->balance <= 0) {
                $payable->status = 'pagada';
                $payable->balance = 0;
            } elseif ($paid > 0) {
                $payable->status = 'parcial';
            } elseif (
                $payable->due_date
                && $payable->due_date->toDateString() < now()->toDateString()
            ) {
                $payable->status = 'vencida';
            } elseif (empty($payable->status) || $payable->status === 'pagada') {
                $payable->status = 'pendiente';
            }
        });
    }
}
