<?php

namespace App\Services;

use App\Models\Receivable;
use App\Models\ReceivablePayment;
use App\Models\ReceivablePaymentReversal;
use App\Models\Sale;
use App\Scopes\BranchScope;
use Closure;
use Illuminate\Contracts\Database\ConcurrencyErrorDetector;
use Illuminate\Database\Connection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use InvalidArgumentException;
use RuntimeException;
use Throwable;

final class ReceivablePaymentService
{
    public function collect(
        Receivable $receivable,
        float $amount,
        string $paymentMethod,
        ?string $paymentDate = null,
        ?string $reference = null,
        ?string $notes = null,
        ?string $requestKey = null,
        bool $updateCashSession = true,
    ): ReceivablePayment {
        $receivable->assertBelongsToCurrentBusiness();
        if (auth()->user() && ! auth()->user()->belongsToBusiness((string) $receivable->business_id)) {
            abort(403);
        }
        if (! is_finite($amount)) {
            throw new InvalidArgumentException('El monto del cobro debe ser finito.');
        }

        $amountCents = SaleCreditService::cents(number_format($amount, 2, '.', ''));
        if ($amountCents <= 0) {
            throw new InvalidArgumentException('El monto del cobro debe ser mayor que cero.');
        }

        $method = $this->normalizeMethod($paymentMethod);
        $date = $paymentDate ?: now()->toDateString();
        $requestKey = $this->normalizeRequestKey($requestKey);
        $payloadHash = $this->hash([
            'business_id' => (string) $receivable->business_id,
            'receivable_id' => (string) $receivable->getKey(),
            'amount_cents' => $amountCents,
            'payment_method' => $method,
            'payment_date' => $date,
            'reference' => $reference,
            'notes' => $notes,
        ]);

        $connection = $receivable->getConnection();
        if ($connection !== DB::connection() || $connection->getDriverName() !== 'mysql') {
            throw new RuntimeException('Cobros de CxC requieren una conexión MySQL compartida.');
        }

        return $this->transaction($connection, function () use (
            $receivable,
            $amountCents,
            $method,
            $date,
            $reference,
            $notes,
            $requestKey,
            $payloadHash,
            $updateCashSession,
            $connection,
        ): ReceivablePayment {
            $locked = $this->lockReceivable($receivable);

            $existing = ReceivablePayment::withoutBusinessScope()
                ->where('business_id', $locked->business_id)
                ->where('request_key', $requestKey)
                ->lockForUpdate()
                ->first();
            if ($existing) {
                if (! hash_equals((string) $existing->payload_hash, $payloadHash)) {
                    throw ValidationException::withMessages([
                        'request_key' => 'La clave de cobro ya fue utilizada con otros datos.',
                    ]);
                }

                return $existing;
            }

            $paidCents = $this->paidCents($locked);
            $amountCentsOwed = SaleCreditService::cents($locked->amount);
            $balanceCents = max(0, $amountCentsOwed - $paidCents);
            if ($balanceCents <= 0) {
                throw new RuntimeException('Esta cuenta ya está cobrada.');
            }
            if ($amountCents > $balanceCents) {
                throw new InvalidArgumentException(
                    'El cobro supera el saldo pendiente de RD$ '.SaleCreditService::decimal($balanceCents).'.'
                );
            }

            $userId = auth()->id() ? (string) auth()->id() : null;
            $cashSession = null;
            if (in_array($method, ['efectivo', 'cash'], true)) {
                if (! $updateCashSession) {
                    throw new RuntimeException('No se puede omitir la caja al registrar un cobro en efectivo.');
                }
                $cashSession = app(CashSessionService::class)
                    ->lockOpenSessionForMovement((string) $locked->business_id, $userId, now());
            }

            $paymentId = (string) Str::uuid();
            $now = now();
            $connection->table('receivable_payments')->insert([
                'id' => $paymentId,
                'receivable_id' => $locked->id,
                'business_id' => $locked->business_id,
                'cash_session_id' => $cashSession?->id,
                'request_key' => $requestKey,
                'payload_hash' => $payloadHash,
                'amount' => SaleCreditService::decimal($amountCents),
                'payment_method' => $method,
                'payment_date' => $date,
                'reference' => $reference,
                'notes' => $notes,
                'created_by' => $userId,
                'created_at' => $now,
                'updated_at' => $now,
            ]);

            $payment = ReceivablePayment::withoutBusinessScope()
                ->where('business_id', $locked->business_id)
                ->whereKey($paymentId)
                ->firstOrFail();

            if ($cashSession) {
                app(CashSessionService::class)->recordMovementForSession(
                    $cashSession,
                    (string) $locked->business_id,
                    'receivable_payment',
                    $paymentId,
                    'in',
                    $amountCents,
                    $userId,
                );
            }

            $locked->refreshPaymentStatus();
            if (SaleCreditService::cents($locked->paid_amount) > SaleCreditService::cents($locked->amount)) {
                throw new RuntimeException('El cobro dejaría la cuenta por cobrar con sobrepago.');
            }
            AccountingService::createFromReceivablePayment($payment);

            return $payment;
        });
    }

    public function reverse(
        ReceivablePayment $payment,
        string $reason,
        ?string $requestKey = null,
    ): ReceivablePaymentReversal {
        if (auth()->user() && ! auth()->user()->belongsToBusiness((string) $payment->business_id)) {
            abort(403);
        }
        $reason = trim($reason);
        if ($reason === '' || mb_strlen($reason) > 2000) {
            throw new InvalidArgumentException('Indica el motivo de la anulación (máximo 2,000 caracteres).');
        }
        $requestKey = $this->normalizeRequestKey($requestKey);
        $payloadHash = $this->hash([
            'business_id' => (string) $payment->business_id,
            'payment_id' => (string) $payment->getKey(),
            'reason' => $reason,
        ]);

        $connection = $payment->getConnection();
        if ($connection !== DB::connection() || $connection->getDriverName() !== 'mysql') {
            throw new RuntimeException('Anulaciones de CxC requieren una conexión MySQL compartida.');
        }

        return $this->transaction($connection, function () use (
            $payment,
            $reason,
            $requestKey,
            $payloadHash,
        ): ReceivablePaymentReversal {
            $parent = Receivable::withoutBusinessScope()
                ->where('business_id', $payment->business_id)
                ->whereKey($payment->receivable_id)
                ->firstOrFail();
            $lockedReceivable = $this->lockReceivable($parent);

            $lockedPayment = ReceivablePayment::withoutBusinessScope()
                ->where('business_id', $lockedReceivable->business_id)
                ->where('receivable_id', $lockedReceivable->id)
                ->whereKey($payment->getKey())
                ->lockForUpdate()
                ->firstOrFail();

            $existingByKey = ReceivablePaymentReversal::withoutBusinessScope()
                ->where('business_id', $lockedReceivable->business_id)
                ->where('request_key', $requestKey)
                ->lockForUpdate()
                ->first();
            if ($existingByKey) {
                if (! hash_equals((string) $existingByKey->payload_hash, $payloadHash)) {
                    throw ValidationException::withMessages([
                        'request_key' => 'La clave de anulación ya fue utilizada con otros datos.',
                    ]);
                }

                return $existingByKey;
            }

            $existingForPayment = ReceivablePaymentReversal::withoutBusinessScope()
                ->where('business_id', $lockedReceivable->business_id)
                ->where('receivable_payment_id', $lockedPayment->id)
                ->lockForUpdate()
                ->first();
            if ($existingForPayment) {
                throw new RuntimeException('Este cobro ya fue anulado.');
            }

            $method = strtolower((string) $lockedPayment->payment_method);
            $cashSession = null;
            if (in_array($method, ['efectivo', 'cash'], true)) {
                $cashSession = app(CashSessionService::class)->lockOpenSessionForMovement(
                    (string) $lockedReceivable->business_id,
                    auth()->id() ? (string) auth()->id() : null,
                    now(),
                );
            }

            $reversal = new ReceivablePaymentReversal([
                'receivable_payment_id' => $lockedPayment->id,
                'cash_session_id' => $cashSession?->id,
                'request_key' => $requestKey,
                'payload_hash' => $payloadHash,
                'reason' => $reason,
                'created_by' => auth()->id(),
            ]);
            $reversal->business_id = $lockedReceivable->business_id;
            $reversal->saveQuietly();

            if ($cashSession) {
                app(CashSessionService::class)->recordMovementForSession(
                    $cashSession,
                    (string) $lockedReceivable->business_id,
                    'receivable_payment_reversal',
                    (string) $reversal->id,
                    'out',
                    SaleCreditService::cents($lockedPayment->amount),
                    auth()->id() ? (string) auth()->id() : null,
                );
            }

            $lockedReceivable->refreshPaymentStatus();
            AccountingService::createFromReceivablePaymentReversal($reversal);

            return $reversal;
        });
    }

    /**
     * Ejecuta cobros y anulaciones en READ COMMITTED cuando abren la transacción: cada lectura posterior
     * a una espera de bloqueo ve los commits concurrentes y las búsquedas por clave no bloquean huecos
     * del índice compartidos con otras empresas. Reintenta hasta 3 veces ante bloqueos mutuos o esperas agotadas.
     */
    private function transaction(Connection $connection, Closure $callback): mixed
    {
        for ($attempt = 1; ; $attempt++) {
            $topLevel = $connection->transactionLevel() === 0;
            if ($topLevel && in_array($connection->getDriverName(), ['mysql', 'mariadb'], true)) {
                $connection->unprepared('SET TRANSACTION ISOLATION LEVEL READ COMMITTED');
            }
            try {
                return $connection->transaction($callback);
            } catch (Throwable $exception) {
                if (! $topLevel || $attempt >= 3
                    || ! app(ConcurrencyErrorDetector::class)->causedByConcurrencyError($exception)) {
                    throw $exception;
                }
            }
        }
    }

    private function lockReceivable(Receivable $receivable): Receivable
    {
        if ($receivable->sale_id !== null) {
            $sale = Sale::withoutBusinessScope()->withoutGlobalScope(BranchScope::class)
                ->where('business_id', $receivable->business_id)
                ->whereKey($receivable->sale_id)
                ->firstOrFail();

            return app(SaleCreditService::class)->assertReceivable($sale);
        }

        return Receivable::withoutBusinessScope()
            ->where('business_id', $receivable->business_id)
            ->whereKey($receivable->getKey())
            ->lockForUpdate()
            ->firstOrFail();
    }

    private function paidCents(Receivable $receivable): int
    {
        // Lecturas con bloqueo: nunca usar la instantánea anterior a la espera del bloqueo de la CxC.
        $gross = DB::table('receivable_payments')
            ->where('business_id', $receivable->business_id)
            ->where('receivable_id', $receivable->id)
            ->lockForUpdate()
            ->sum('amount');

        $reversed = DB::table('receivable_payment_reversals as reversals')
            ->join('receivable_payments as payments', function ($join): void {
                $join->on('payments.id', '=', 'reversals.receivable_payment_id')
                    ->on('payments.business_id', '=', 'reversals.business_id');
            })
            ->where('payments.business_id', $receivable->business_id)
            ->where('payments.receivable_id', $receivable->id)
            ->lockForUpdate()
            ->sum('payments.amount');

        return max(0, SaleCreditService::cents($gross) - SaleCreditService::cents($reversed));
    }

    private function normalizeMethod(string $method): string
    {
        return match (strtolower(trim($method))) {
            'efectivo', 'cash' => 'efectivo',
            'tarjeta', 'card' => 'tarjeta',
            'transferencia', 'transfer' => 'transferencia',
            'cheque' => 'cheque',
            default => throw new InvalidArgumentException('El método de cobro no es válido para CxC.'),
        };
    }

    private function normalizeRequestKey(?string $requestKey): string
    {
        $requestKey = trim((string) $requestKey);
        if ($requestKey === '') {
            return (string) Str::uuid();
        }
        if (strlen($requestKey) > 100 || ! preg_match('/\A[\x21-\x7E]+\z/', $requestKey)) {
            throw new InvalidArgumentException('La clave de idempotencia no es válida.');
        }

        return $requestKey;
    }

    private function hash(array $payload): string
    {
        return hash('sha256', json_encode($payload, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE));
    }
}
