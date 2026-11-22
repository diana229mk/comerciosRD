<?php

namespace App\Models;

use App\Database\ReceivableQueryBuilder;
use App\Services\ReceivablePaymentService;
use App\Services\SaleCreditService;
use App\Services\SaleReceivableIntegrity;
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
    use SoftDeletes {
        forceDestroy as private forceDestroyUnprotected;
    }

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
            'original_amount' => 'decimal:2',
            'amount' => 'decimal:2',
            'paid_amount' => 'decimal:2',
            'balance' => 'decimal:2',
            'due_date' => 'date',
        ];
    }

    public function save(array $options = [])
    {
        return app(SaleReceivableIntegrity::class)->modelWrite(
            $this, 'update', fn () => parent::save($options),
        );
    }

    public static function destroy($ids)
    {
        $arguments = func_get_args();

        return (new static)->getConnection()->transaction(fn () => parent::destroy(...$arguments));
    }

    public static function forceDestroy($ids)
    {
        $arguments = func_get_args();

        return (new static)->getConnection()->transaction(fn () => static::forceDestroyUnprotected(...$arguments));
    }

    public function delete()
    {
        if (! $this->exists) {
            return parent::delete();
        }

        return app(SaleReceivableIntegrity::class)->modelWrite(
            $this, 'delete', fn () => parent::delete(),
        );
    }

    protected function newBaseQueryBuilder()
    {
        $connection = $this->getConnection();

        return new ReceivableQueryBuilder(
            $connection, $connection->getQueryGrammar(), $connection->getPostProcessor(),
        );
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
        if ($this->sale_id !== null) {
            app(SaleCreditService::class)->refreshPayments($this);

            return;
        }
        $gross = (float) $this->payments()->lockForUpdate()->sum('amount');
        $reversed = (float) $this->payments()
            ->join('receivable_payment_reversals as reversals', function ($join): void {
                $join->on('reversals.receivable_payment_id', '=', 'receivable_payments.id')
                    ->on('reversals.business_id', '=', 'receivable_payments.business_id');
            })
            ->lockForUpdate()
            ->sum('receivable_payments.amount');
        $paid = round(max(0, $gross - $reversed), 2);
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
     * Registra un cobro de forma atómica (lockForUpdate) para evitar sobrepagos concurrentes (DATA-02).
     */
    public function registerPayment(
        float $amount,
        string $paymentMethod = 'efectivo',
        ?string $paymentDate = null,
        ?string $reference = null,
        ?string $notes = null,
        bool $updateCashSession = true,
        ?string $requestKey = null,
    ): ReceivablePayment {
        return app(ReceivablePaymentService::class)->collect(
            receivable: $this,
            amount: $amount,
            paymentMethod: $paymentMethod,
            paymentDate: $paymentDate,
            reference: $reference,
            notes: $notes,
            requestKey: $requestKey,
            updateCashSession: $updateCashSession,
        );
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
            if ($receivable->sale_id !== null) {
                return;
            }
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
