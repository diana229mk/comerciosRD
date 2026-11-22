<?php

namespace App\Services;

use App\Models\Sale;
use App\Models\SaleReturnOperation;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use RuntimeException;

final class SaleReturnOperationService
{
    public function settleRefund(
        SaleReturnOperation $operation,
        string $paymentMethod,
        string $requestKey,
    ): SaleReturnOperation {
        $operation->assertBelongsToCurrentBusiness();
        $method = $this->normalizeRefundMethod($paymentMethod);
        $requestKey = $this->normalizeRequestKey($requestKey);
        $payloadHash = hash('sha256', json_encode([
            'business_id' => (string) $operation->business_id,
            'operation_id' => (string) $operation->id,
            'amount' => (string) $operation->refund_due_amount,
            'payment_method' => $method,
        ], JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE));

        return DB::transaction(function () use ($operation, $method, $requestKey, $payloadHash): SaleReturnOperation {
            $sale = Sale::withoutBusinessScope()
                ->where('business_id', $operation->business_id)
                ->whereKey($operation->sale_id)
                ->lockForUpdate()
                ->firstOrFail();
            if (! app(SaleService::class)->canReturn($sale)) {
                abort(403, 'No tienes permiso para liquidar este reembolso.');
            }
            $locked = SaleReturnOperation::withoutBusinessScope()
                ->where('business_id', $sale->business_id)
                ->whereKey($operation->id)
                ->lockForUpdate()
                ->firstOrFail();

            if ($locked->refund_status === 'settled') {
                if ($locked->refund_settlement_key === $requestKey
                    && hash_equals((string) $locked->refund_settlement_hash, $payloadHash)) {
                    return $locked;
                }

                throw new RuntimeException('Este reembolso ya fue liquidado.');
            }
            if ($locked->refund_status !== 'pending' || (float) $locked->refund_due_amount <= 0) {
                throw new RuntimeException('No hay un reembolso pendiente para liquidar.');
            }

            $cashSession = null;
            if ($method === 'efectivo') {
                $cashSession = app(CashSessionService::class)->lockOpenSessionForMovement(
                    (string) $sale->business_id,
                    auth()->id() ? (string) auth()->id() : null,
                    now(),
                );
            }

            $locked->forceFill([
                'refund_status' => 'settled',
                'refund_method' => $method,
                'refund_cash_session_id' => $cashSession?->id,
                'refund_settlement_key' => $requestKey,
                'refund_settlement_hash' => $payloadHash,
                'refund_settled_at' => now(),
            ])->saveQuietly();

            if ($cashSession) {
                app(CashSessionService::class)->recordMovementForSession(
                    $cashSession,
                    (string) $sale->business_id,
                    'sale_return_refund',
                    (string) $locked->id,
                    'out',
                    SaleCreditService::cents($locked->refund_due_amount),
                    auth()->id() ? (string) auth()->id() : null,
                );
            }

            AccountingService::createFromSaleRefundSettlement($locked);

            return $locked;
        }, 3);
    }

    /**
     * @return array<string, string>
     */
    public function pendingRefundsForSale(Sale $sale): array
    {
        $sale->assertBelongsToCurrentBusiness();
        if (! app(SaleService::class)->canReturn($sale)) {
            return [];
        }

        return SaleReturnOperation::withoutBusinessScope()
            ->where('business_id', $sale->business_id)
            ->where('sale_id', $sale->id)
            ->where('refund_status', 'pending')
            ->orderBy('created_at')
            ->get()
            ->mapWithKeys(fn (SaleReturnOperation $operation): array => [
                (string) $operation->id => 'RD$ '.number_format((float) $operation->refund_due_amount, 2)
                    .' · '.(string) $operation->refund_note,
            ])
            ->all();
    }

    /**
     * Processes durable credit-note requests without relying on Redis publication.
     *
     * @return array{selected:int, issued:int, deferred:int}
     */
    public function retryDueCreditNotes(int $limit = 20): array
    {
        if ($limit < 1 || $limit > 100) {
            throw new \InvalidArgumentException('El límite de reintentos debe estar entre 1 y 100.');
        }

        $ids = SaleReturnOperation::withoutBusinessScope()
            ->where('ecf_requested', true)
            ->where('ecf_status', 'pending')
            ->where('ecf_available_at', '<=', now())
            ->orderBy('ecf_available_at')
            ->orderBy('id')
            ->limit($limit)
            ->pluck('id');

        $issued = 0;
        $deferred = 0;
        foreach ($ids as $id) {
            try {
                $wasIssued = DB::transaction(function () use ($id): bool {
                    $operation = SaleReturnOperation::withoutBusinessScope()
                        ->whereKey($id)
                        ->where('ecf_requested', true)
                        ->where('ecf_status', 'pending')
                        ->where('ecf_available_at', '<=', now())
                        ->lock('for update skip locked')
                        ->first();
                    if (! $operation) {
                        return false;
                    }

                    $sale = Sale::withoutBusinessScope()
                        ->where('business_id', $operation->business_id)
                        ->whereKey($operation->sale_id)
                        ->firstOrFail();
                    $invoice = EcfService::createCreditNoteForReturn(
                        sale: $sale,
                        allocationQuantities: $operation->allocation_quantities,
                        modificationCode: 3,
                    );
                    if ((string) $invoice->business_id !== (string) $operation->business_id
                        || (string) $invoice->sale_id !== (string) $operation->sale_id) {
                        throw new RuntimeException('La Nota de Crédito no coincide con la devolución solicitada.');
                    }

                    $operation->forceFill([
                        'ecf_status' => 'issued',
                        'ecf_attempts' => $operation->ecf_attempts + 1,
                        'ecf_last_error' => null,
                        'electronic_invoice_id' => $invoice->id,
                    ])->saveQuietly();

                    return true;
                }, 3);
                $issued += (int) $wasIssued;
            } catch (\Throwable $exception) {
                $deferred += (int) $this->deferCreditNote((string) $id, $exception);
            }
        }

        return ['selected' => $ids->count(), 'issued' => $issued, 'deferred' => $deferred];
    }

    private function deferCreditNote(string $id, \Throwable $exception): bool
    {
        try {
            return DB::transaction(function () use ($id, $exception): bool {
                $operation = SaleReturnOperation::withoutBusinessScope()
                    ->whereKey($id)
                    ->where('ecf_requested', true)
                    ->where('ecf_status', 'pending')
                    ->lockForUpdate()
                    ->first();
                if (! $operation) {
                    return false;
                }

                $attempts = $operation->ecf_attempts + 1;
                $delay = min(3600, 60 * (2 ** min($attempts - 1, 6)));
                $operation->forceFill([
                    'ecf_attempts' => $attempts,
                    'ecf_available_at' => now()->addSeconds($delay),
                    'ecf_last_error' => mb_substr($exception->getMessage(), 0, 4000),
                ])->saveQuietly();
                Log::warning('sale.return.credit_note.retry_deferred', [
                    'operation_id' => $operation->id,
                    'business_id' => $operation->business_id,
                    'attempt' => $attempts,
                    'error' => $exception->getMessage(),
                ]);

                return true;
            });
        } catch (\Throwable $storageException) {
            Log::error('sale.return.credit_note.retry_state_unavailable', [
                'operation_id' => $id,
                'error' => $storageException->getMessage(),
                'cause' => $exception->getMessage(),
            ]);

            return false;
        }
    }

    private function normalizeRefundMethod(string $method): string
    {
        return match (strtolower(trim($method))) {
            'efectivo', 'cash' => 'efectivo',
            'tarjeta', 'card' => 'tarjeta',
            'transferencia', 'transfer' => 'transferencia',
            'cheque' => 'cheque',
            default => throw new \InvalidArgumentException('El método de reembolso no es válido.'),
        };
    }

    private function normalizeRequestKey(string $requestKey): string
    {
        $requestKey = trim($requestKey);
        if ($requestKey === '' || strlen($requestKey) > 100
            || ! preg_match('/\A[\x21-\x7E]+\z/', $requestKey)) {
            throw new \InvalidArgumentException('La clave de liquidación no es válida.');
        }

        return $requestKey;
    }
}
