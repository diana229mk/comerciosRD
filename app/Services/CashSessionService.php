<?php

namespace App\Services;

use App\Enums\PaymentMethod;
use App\Enums\SaleStatus;
use App\Models\CashSession;
use App\Models\Expense;
use App\Models\Sale;
use App\Models\User;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use RuntimeException;

/**
 * Apertura / cierre de caja y cálculo de arqueo.
 * Un cajero = un turno abierto. Dos cajeros pueden cobrar a la vez.
 */
class CashSessionService
{
    /**
     * Abre un turno de caja para el cajero actual (otros pueden tener el suyo).
     */
    public function open(
        float $openingAmount,
        ?string $notes = null,
        ?string $userId = null,
    ): CashSession {
        $userId = $userId ?? auth()->id();
        if (! $userId) {
            throw new RuntimeException('Debes iniciar sesión para abrir la caja.');
        }

        if ($openingAmount < 0) {
            throw new RuntimeException('El monto de apertura no puede ser negativo.');
        }

        $businessId = app()->bound('currentBusinessId') ? (string) app('currentBusinessId') : '';
        $lock = Cache::lock('cash-open:'.$businessId.':'.$userId, 10);
        if (! $lock->block(5)) {
            throw new RuntimeException('No se pudo abrir la caja. Intenta de nuevo.');
        }

        try {
            return DB::transaction(function () use ($openingAmount, $notes, $userId): CashSession {
                $open = CashSession::query()
                    ->open()
                    ->where('user_id', $userId)
                    ->lockForUpdate()
                    ->first();
                if ($open) {
                    throw new RuntimeException(
                        'Ya tienes una caja abierta (desde '.$open->opened_at?->format('d/m/Y H:i').'). Ciérrala antes de abrir otra.'
                    );
                }

                $session = new CashSession([
                    'user_id' => $userId,
                    'opening_amount' => round($openingAmount, 2),
                    'total_sales_cash' => 0,
                    'total_expenses_cash' => 0,
                    'status' => CashSession::STATUS_OPEN,
                    'opening_notes' => $notes,
                    'opened_at' => now(),
                ]);
                $session->save();

                return $session->fresh(['user']);
            });
        } finally {
            $lock->release();
        }
    }

    /**
     * Cierra la caja: recalcula totales, compara con el contado y guarda diferencia.
     */
    public function close(
        CashSession $session,
        float $closingAmount,
        ?string $notes = null,
        ?string $closedBy = null,
    ): CashSession {
        return DB::transaction(function () use ($session, $closingAmount, $notes, $closedBy): CashSession {
            /** @var CashSession $locked */
            $locked = CashSession::query()
                ->whereKey($session->getKey())
                ->lockForUpdate()
                ->firstOrFail();

            if (! $locked->isOpen()) {
                throw new RuntimeException('Esta sesión de caja ya está cerrada.');
            }

            if ($closingAmount < 0) {
                throw new RuntimeException('El monto de cierre no puede ser negativo.');
            }

            $totals = $this->computePeriodTotals($locked);
            $expected = round(
                (float) $locked->opening_amount
                + $totals['sales']
                - $totals['expenses'],
                2
            );
            $difference = round($closingAmount - $expected, 2);

            $locked->forceFill([
                'total_sales_cash' => $totals['sales'],
                'total_expenses_cash' => $totals['expenses'],
                'expected_amount' => $expected,
                'closing_amount' => round($closingAmount, 2),
                'difference' => $difference,
                'status' => CashSession::STATUS_CLOSED,
                'closing_notes' => $notes,
                'closed_by' => $closedBy ?? auth()->id(),
                'closed_at' => now(),
            ])->save();

            return $locked->fresh(['user', 'closedByUser']);
        });
    }

    /**
     * Recalcula ventas/gastos en efectivo del periodo abierto (sin cerrar).
     *
     * @return array{sales: float, expenses: float, expected: float}
     */
    public function preview(CashSession $session): array
    {
        $totals = $this->computePeriodTotals($session);
        $expected = round(
            (float) $session->opening_amount + $totals['sales'] - $totals['expenses'],
            2
        );

        return [
            'sales' => $totals['sales'],
            'expenses' => $totals['expenses'],
            'expected' => $expected,
        ];
    }

    /**
     * @return array{sales: float, expenses: float}
     */
    protected function computePeriodTotals(CashSession $session): array
    {
        $from = $session->opened_at;
        $to = $session->closed_at ?? now();

        $salesQuery = Sale::query()
            ->where(function ($q) {
                $q->where('payment_method', PaymentMethod::Efectivo->value)
                    ->orWhere('payment_method', PaymentMethod::Cash->value)
                    ->orWhere('payment_method', 'efectivo')
                    ->orWhere('payment_method', 'cash');
            })
            ->where(function ($q) use ($from, $to) {
                $q->whereBetween('sold_at', [$from, $to])
                    ->orWhereBetween('sale_date', [$from->toDateString(), $to->toDateString()]);
            })
            ->where(function ($q) {
                $q->whereNull('status')
                    ->orWhereNotIn('status', ['cancelada', 'anulada', 'cancelled']);
            });
        if ($session->user_id) {
            $salesQuery->where('user_id', $session->user_id);
        }
        $sales = (float) $salesQuery->sum('total');

        $expensesQuery = Expense::query()
            ->where('is_paid', true)
            ->where(function ($q) {
                $q->where('payment_method', 'efectivo')
                    ->orWhere('payment_method', 'cash')
                    ->orWhereNull('payment_method');
            })
            ->whereBetween('expense_date', [$from->toDateString(), $to->toDateString()]);
        if ($session->user_id) {
            $expensesQuery->where('user_id', $session->user_id);
        }
        $expenses = (float) $expensesQuery->sum('amount');

        return [
            'sales' => round($sales, 2),
            'expenses' => round($expenses, 2),
        ];
    }

    public function currentOpen(?User $user = null): ?CashSession
    {
        $userId = $user?->getKey() ?? auth()->id();
        if (! $userId) {
            return CashSession::query()->open()->latest('opened_at')->first();
        }

        return CashSession::query()
            ->open()
            ->where('user_id', $userId)
            ->latest('opened_at')
            ->first();
    }

    /**
     * Estado de caja para el POS (abrir / cerrar / arqueo en vivo).
     *
     * @return array{
     *     open: bool,
     *     can_open: bool,
     *     can_close: bool,
     *     opened_at: ?string,
     *     opened_by: ?string,
     *     opening_amount: ?float,
     *     sales_cash: float,
     *     expected: ?float,
     *     session_id: ?string
     * }
     */
    public function posSnapshot(?User $user = null): array
    {
        $user = $user instanceof User ? $user : (auth()->user() instanceof User ? auth()->user() : null);
        $open = $this->currentOpen($user);
        if ($open && ! $open->relationLoaded('user')) {
            $open->load('user:id,name');
        }
        $preview = $open ? $this->preview($open) : null;
        $canOpen = $user?->canPerform('open_cash') ?? false;
        $canClose = $user?->canPerform('close_cash') ?? false;

        return [
            'open' => $open !== null,
            'can_open' => $canOpen && $open === null,
            'can_close' => $canClose && $open !== null,
            'opened_at' => $open?->opened_at?->format('d/m/Y H:i'),
            'opened_by' => $open?->user?->name,
            'opening_amount' => $open !== null ? (float) $open->opening_amount : null,
            'sales_cash' => (float) ($preview['sales'] ?? 0),
            'expected' => $preview['expected'] ?? null,
            'session_id' => $open ? (string) $open->getKey() : null,
        ];
    }

    /**
     * Actualiza en tiempo real total_sales_cash de la sesión abierta
     * cuando se completa una venta en efectivo.
     *
     * - Solo payment_method efectivo (y legacy "cash")
     * - Si no hay caja abierta → no hace nada (sin error)
     * - El cierre de caja sigue recalculando desde el histórico (fuente de verdad)
     */
    public function registerCashSale(Sale $sale): void
    {
        if (! $this->isCashSale($sale)) {
            return;
        }

        $this->registerCashAmount(
            (string) $sale->business_id,
            (float) $sale->total,
            $sale->sold_at ?? $sale->sale_date ?? now(),
            $sale->user_id ? (string) $sale->user_id : ($sale->created_by ? (string) $sale->created_by : null),
        );
    }

    /**
     * Suma un monto en efectivo a la caja abierta del negocio
     * (cobros de CxC, etc.). No falla si no hay sesión abierta.
     */
    public function registerCashAmount(
        string $businessId,
        float $amount,
        mixed $asOf = null,
        ?string $userId = null,
    ): void {
        $amount = round($amount, 2);
        if ($amount <= 0 || $businessId === '') {
            return;
        }

        $asOf = $asOf ?? now();
        $userId = $userId ?: (auth()->id() ? (string) auth()->id() : null);

        $query = CashSession::withoutBusinessScope()
            ->where('business_id', $businessId)
            ->where('status', CashSession::STATUS_OPEN)
            ->where('opened_at', '<=', $asOf)
            ->orderByDesc('opened_at');
        if ($userId) {
            $query->where('user_id', $userId);
        }

        $session = (clone $query)->lockForUpdate()->first();

        if (! $session) {
            $session = $query->first();
        }

        if (! $session) {
            return;
        }

        $session->increment('total_sales_cash', $amount);
    }

    /**
     * Resta un monto en efectivo de la caja abierta (anulación de cobro).
     */
    public function reverseCashAmount(string $businessId, float $amount, ?string $userId = null): bool
    {
        $amount = round($amount, 2);
        if ($amount <= 0 || $businessId === '') {
            return false;
        }

        $userId = $userId ?: (auth()->id() ? (string) auth()->id() : null);

        $query = CashSession::withoutBusinessScope()
            ->where('business_id', $businessId)
            ->where('status', CashSession::STATUS_OPEN)
            ->orderByDesc('opened_at');
        if ($userId) {
            $query->where('user_id', $userId);
        }
        $session = $query->first();

        if (! $session) {
            return false;
        }

        $newTotal = max(0, round((float) $session->total_sales_cash - $amount, 2));
        $session->forceFill(['total_sales_cash' => $newTotal])->saveQuietly();

        return true;
    }

    /**
     * Revierte el impacto en caja al anular una venta en efectivo.
     */
    public function reverseCashSale(Sale $sale): void
    {
        if (! $this->isCashPaymentMethod($sale->payment_method)) {
            return;
        }

        $amount = round((float) $sale->total, 2);
        if ($amount <= 0) {
            return;
        }

        $session = $this->openSessionForSale($sale, lock: true);
        if (! $session) {
            return;
        }

        $newTotal = max(0, round((float) $session->total_sales_cash - $amount, 2));
        $session->forceFill(['total_sales_cash' => $newTotal])->saveQuietly();
    }

    /**
     * ¿La venta completada se pagó en efectivo?
     */
    public function isCashSale(Sale $sale): bool
    {
        $status = $sale->status;
        $completed = $status instanceof SaleStatus
            ? $status->isCompleted()
            : in_array((string) $status, ['completada', 'completed'], true);

        if (! $completed) {
            return false;
        }

        return $this->isCashPaymentMethod($sale->payment_method);
    }

    protected function isCashPaymentMethod(mixed $method): bool
    {
        $value = $method instanceof PaymentMethod
            ? $method->value
            : (string) ($method ?? '');

        $normalized = PaymentMethod::normalize($value !== '' ? $value : 'efectivo');

        return in_array($normalized, [PaymentMethod::Efectivo, PaymentMethod::Cash], true);
    }

    /**
     * Sesión abierta del mismo negocio que cubre la fecha de la venta.
     */
    protected function openSessionForSale(Sale $sale, bool $lock = false): ?CashSession
    {
        $soldAt = $sale->sold_at ?? $sale->sale_date ?? now();

        $query = CashSession::withoutBusinessScope()
            ->where('business_id', $sale->business_id)
            ->where('status', CashSession::STATUS_OPEN)
            ->where('opened_at', '<=', $soldAt)
            ->orderByDesc('opened_at');
        $cashierId = $sale->user_id ?: $sale->created_by;
        if ($cashierId) {
            $query->where('user_id', $cashierId);
        }

        if ($lock) {
            $query->lockForUpdate();
        }

        return $query->first();
    }
}
