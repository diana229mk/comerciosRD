<?php

namespace App\Services;

use App\Enums\PaymentMethod;
use App\Enums\SaleStatus;
use App\Models\Account;
use App\Models\AccountingOutbox;
use App\Models\Business;
use App\Models\Expense;
use App\Models\JournalEntry;
use App\Models\JournalEntryLine;
use App\Models\ReceivablePayment;
use App\Models\ReceivablePaymentReversal;
use App\Models\Sale;
use App\Models\SaleReturnOperation;
use App\Support\TenantContext;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class AccountingService
{
    /**
     * Crea las cuentas contables por defecto para un negocio.
     * Idempotente: no duplica códigos existentes.
     */
    public static function createDefaultAccounts(Business $business): void
    {
        $accounts = [
            ['code' => '1101', 'name' => 'Caja', 'type' => 'asset', 'is_system' => true],
            ['code' => '1102', 'name' => 'Banco', 'type' => 'asset', 'is_system' => true],
            ['code' => '1201', 'name' => 'Cuentas por Cobrar', 'type' => 'asset', 'is_system' => true],
            ['code' => '1301', 'name' => 'Inventario', 'type' => 'asset', 'is_system' => true],
            ['code' => '2101', 'name' => 'Cuentas por Pagar', 'type' => 'liability', 'is_system' => true],
            ['code' => '2201', 'name' => 'ITBIS por Pagar', 'type' => 'liability', 'is_system' => true],
            ['code' => '2301', 'name' => 'Reembolsos a Clientes por Pagar', 'type' => 'liability', 'is_system' => true],
            ['code' => '3101', 'name' => 'Capital', 'type' => 'equity', 'is_system' => true],
            ['code' => '3201', 'name' => 'Utilidades Retenidas', 'type' => 'equity', 'is_system' => true],
            ['code' => '4101', 'name' => 'Ventas', 'type' => 'income', 'is_system' => true],
            ['code' => '4201', 'name' => 'Otros Ingresos', 'type' => 'income', 'is_system' => true],
            ['code' => '5101', 'name' => 'Costo de Ventas', 'type' => 'cost', 'is_system' => true],
            ['code' => '6101', 'name' => 'Gastos Operativos', 'type' => 'expense', 'is_system' => true],
            ['code' => '6201', 'name' => 'Gastos Administrativos', 'type' => 'expense', 'is_system' => true],
            ['code' => '6301', 'name' => 'Gastos de Ventas', 'type' => 'expense', 'is_system' => true],
        ];

        foreach ($accounts as $account) {
            $exists = Account::withoutBusinessScope()
                ->where('business_id', $business->getKey())
                ->where('code', $account['code'])
                ->exists();

            if ($exists) {
                continue;
            }

            $model = new Account([
                'code' => $account['code'],
                'name' => $account['name'],
                'type' => $account['type'],
                'is_system' => $account['is_system'],
                'is_active' => true,
            ]);
            $model->business_id = $business->getKey();
            // Provisioning is explicitly tenant-targeted; ambient Filament context must not rewrite it.
            $model->saveQuietly();
        }
    }

    /**
     * Genera asiento contable automático desde una Venta completada.
     *
     * Asiento:
     *  Débito  Caja/Banco/CxC     = total
     *  Crédito Ventas             = subtotal
     *  Crédito ITBIS por Pagar    = tax (si > 0)
     *  Débito  Costo de Ventas    = costo
     *  Crédito Inventario         = costo
     */
    public static function createFromSale(Sale $sale): void
    {
        try {
            TenantContext::run((string) $sale->business_id, function () use ($sale): void {
                DB::transaction(function () use ($sale): void {
                    $sale = Sale::withoutBusinessScope()
                        ->where('business_id', $sale->business_id)
                        ->whereKey($sale->getKey())
                        ->lockForUpdate()
                        ->firstOrFail();

                    $outbox = app(AccountingOutboxService::class)->register($sale);
                    $outbox = AccountingOutbox::withoutBusinessScope()
                        ->where('business_id', $sale->business_id)
                        ->whereKey($outbox->id)->lockForUpdate()->firstOrFail();
                    if (in_array($outbox->status, ['processed', 'skipped'], true)) {
                        return;
                    }

                    if ($outbox->status === 'failed') {
                        throw new \RuntimeException('Evento contable agotado; requiere recuperación explícita.');
                    }

                    if (! self::saleIsCompleted($sale)) {
                        $outbox->forceFill(['status' => 'skipped', 'lease_token' => null])->save();

                        return;
                    }
                    if ($sale->isCreditSale() && (int) $sale->credit_version === 1) {
                        app(SaleCreditService::class)->assertReceivable($sale);
                    }

                    $saleNumber = $sale->sale_number ?: $sale->number ?: $sale->id;
                    $reference = 'Venta #'.$saleNumber;

                    $existing = JournalEntry::withoutBusinessScope()->withTrashed()
                        ->where('business_id', $sale->business_id)
                        ->where('reference', $reference)
                        ->limit(2)->get();
                    if ($existing->isNotEmpty()) {
                        $entry = JournalEntry::withoutBusinessScope()->withTrashed()
                            ->where('business_id', $sale->business_id)
                            ->whereKey($existing->first()->id)->lockForUpdate()->first();
                        if (! $entry || $entry->reference !== $reference
                            || $existing->count() !== 1 || $entry->trashed()
                            || ! $entry->isPosted() || ! $entry->isBalanced()
                            || $entry->lines()->count() === 0
                            || round((float) $entry->lines()->sum('debit'), 2) !== (float) $entry->total_debit
                            || round((float) $entry->lines()->sum('credit'), 2) !== (float) $entry->total_credit) {
                            throw new \RuntimeException('Referencia contable heredada ambigua o inválida; requiere revisión.');
                        }
                        self::finishSaleEvent($outbox, $entry);

                        return;
                    }

                    $sale->loadMissing(['items.product']);
                    if ($sale->items->isEmpty()) {
                        throw new \RuntimeException('La venta completada no tiene líneas contables recuperables.');
                    }

                    $accounts = Account::withoutBusinessScope()
                        ->where('business_id', $sale->business_id)
                        ->where('is_system', true)
                        ->get()
                        ->keyBy('code');

                    if ($accounts->isEmpty()) {
                        throw new \RuntimeException('No existen cuentas contables para generar el asiento de venta.');
                    }

                    $paymentAccountCode = self::paymentAccountCode($sale->payment_method);
                    $paymentAccount = $accounts[$paymentAccountCode] ?? null;
                    $salesAccount = $accounts['4101'] ?? null;
                    $taxAccount = $accounts['2201'] ?? null;
                    $cogsAccount = $accounts['5101'] ?? null;
                    $inventoryAccount = $accounts['1301'] ?? null;

                    if (! $paymentAccount || ! $salesAccount || ((float) $sale->tax > 0 && ! $taxAccount)) {
                        throw new \RuntimeException('Faltan cuentas obligatorias de cobro, ventas o ITBIS para generar el asiento.');
                    }

                    $entry = new JournalEntry([
                        'date' => $sale->sale_date?->toDateString()
                            ?? $sale->sold_at?->toDateString()
                            ?? now()->toDateString(),
                        'reference' => $reference,
                        'description' => 'Asiento automático por venta '.$saleNumber,
                        'status' => JournalEntry::STATUS_POSTED,
                        'created_by' => $sale->created_by ?? $sale->user_id,
                        'total_debit' => 0,
                        'total_credit' => 0,
                    ]);
                    $entry->business_id = $sale->business_id;
                    $entry->save();

                    $totalDebit = 0.0;
                    $totalCredit = 0.0;

                    $total = round((float) $sale->total, 2);
                    $tax = round((float) $sale->tax, 2);
                    // Ventas netas = total - ITBIS (equivale a subtotal - descuento)
                    $salesCredit = round($total - $tax, 2);

                    // 1. Débito: Caja / Banco / CxC
                    JournalEntryLine::create([
                        'journal_entry_id' => $entry->id,
                        'account_id' => $paymentAccount->id,
                        'debit' => $total,
                        'credit' => 0,
                        'description' => 'Cobro de venta',
                    ]);
                    $totalDebit += $total;

                    // 2. Crédito: Ventas (neto de descuento)
                    JournalEntryLine::create([
                        'journal_entry_id' => $entry->id,
                        'account_id' => $salesAccount->id,
                        'debit' => 0,
                        'credit' => $salesCredit,
                        'description' => 'Ingreso por ventas',
                    ]);
                    $totalCredit += $salesCredit;

                    // 3. Crédito: ITBIS por Pagar
                    if ($tax > 0 && $taxAccount) {
                        JournalEntryLine::create([
                            'journal_entry_id' => $entry->id,
                            'account_id' => $taxAccount->id,
                            'debit' => 0,
                            'credit' => $tax,
                            'description' => 'ITBIS cobrado',
                        ]);
                        $totalCredit += $tax;
                    }

                    // 4. Costo de Ventas + Inventario
                    // DATA-03: un unit_cost explícito de 0 es legítimo (producto sin costo).
                    // Solo se usa el costo actual del producto cuando unit_cost es null (dato faltante).
                    $totalCost = 0.0;
                    foreach ($sale->items as $item) {
                        $unitCost = $item->unit_cost;
                        if ($unitCost === null) {
                            $unitCost = $item->product?->cost ?? 0;
                        }
                        $totalCost += (float) $unitCost * (float) $item->quantity;
                    }
                    $totalCost = round($totalCost, 2);

                    if ($totalCost > 0 && (! $cogsAccount || ! $inventoryAccount)) {
                        throw new \RuntimeException('Faltan las cuentas de costo o inventario para generar el asiento.');
                    }

                    if ($totalCost > 0) {
                        JournalEntryLine::create([
                            'journal_entry_id' => $entry->id,
                            'account_id' => $cogsAccount->id,
                            'debit' => $totalCost,
                            'credit' => 0,
                            'description' => 'Costo de productos vendidos',
                        ]);
                        $totalDebit += $totalCost;

                        JournalEntryLine::create([
                            'journal_entry_id' => $entry->id,
                            'account_id' => $inventoryAccount->id,
                            'debit' => 0,
                            'credit' => $totalCost,
                            'description' => 'Salida de inventario',
                        ]);
                        $totalCredit += $totalCost;
                    }

                    $entry->forceFill([
                        'total_debit' => round($totalDebit, 2),
                        'total_credit' => round($totalCredit, 2),
                    ])->saveQuietly();

                    if (round($totalDebit, 2) !== round($totalCredit, 2)) {
                        throw new \RuntimeException('El asiento automático de la venta quedó desbalanceado.');
                    }
                    self::finishSaleEvent($outbox, $entry);
                });
            });
        } catch (\Throwable $e) {
            Log::error('AccountingService::createFromSale falló', [
                'sale_id' => $sale->id,
                'error' => $e->getMessage(),
            ]);
            throw $e;
        }
    }

    protected static function finishSaleEvent(AccountingOutbox $event, JournalEntry $entry): void
    {
        $event->forceFill([
            'status' => 'processed',
            'journal_entry_id' => $entry->id,
            'processed_at' => now(),
            'lease_token' => null,
            'processing_attempts' => $event->processing_attempts + 1,
        ])->save();
    }

    /**
     * Genera asiento contable automático desde un Gasto.
     *
     * Asiento:
     *  Débito  Gastos Operativos = amount
     *  Crédito Caja/Banco        = amount
     */
    public static function createFromExpense(Expense $expense): void
    {
        try {
            TenantContext::run((string) $expense->business_id, function () use ($expense): void {
                DB::transaction(function () use ($expense): void {
                    $expense = Expense::withoutBusinessScope()
                        ->where('business_id', $expense->business_id)
                        ->whereKey($expense->getKey())
                        ->lockForUpdate()
                        ->firstOrFail();

                    $reference = 'Gasto #'.$expense->getKey();

                    if (JournalEntry::withoutBusinessScope()
                        ->where('business_id', $expense->business_id)
                        ->where('reference', $reference)
                        ->exists()
                    ) {
                        return;
                    }

                    $amount = round((float) $expense->amount, 2);
                    if ($amount <= 0) {
                        return;
                    }

                    $accounts = Account::withoutBusinessScope()
                        ->where('business_id', $expense->business_id)
                        ->where('is_system', true)
                        ->get()
                        ->keyBy('code');

                    $expenseAccount = $accounts['6101'] ?? null; // Gastos Operativos
                    $paymentCode = self::expensePaymentAccountCode($expense->payment_method);
                    $cashAccount = $accounts[$paymentCode] ?? $accounts['1101'] ?? null;

                    if (! $expenseAccount || ! $cashAccount) {
                        throw new \RuntimeException('Faltan las cuentas de gasto o de pago para generar el asiento.');
                    }

                    $entry = new JournalEntry([
                        'date' => $expense->expense_date?->toDateString()
                            ?? $expense->created_at?->toDateString()
                            ?? now()->toDateString(),
                        'reference' => $reference,
                        'description' => 'Asiento automático por gasto: '.($expense->description ?? 'Sin descripción'),
                        'status' => JournalEntry::STATUS_POSTED,
                        'created_by' => $expense->created_by ?? $expense->user_id ?? auth()->id(),
                        'total_debit' => $amount,
                        'total_credit' => $amount,
                    ]);
                    $entry->business_id = $expense->business_id;
                    $entry->save();

                    JournalEntryLine::create([
                        'journal_entry_id' => $entry->id,
                        'account_id' => $expenseAccount->id,
                        'debit' => $amount,
                        'credit' => 0,
                        'description' => $expense->description ?? 'Gasto',
                    ]);

                    JournalEntryLine::create([
                        'journal_entry_id' => $entry->id,
                        'account_id' => $cashAccount->id,
                        'debit' => 0,
                        'credit' => $amount,
                        'description' => 'Pago de gasto',
                    ]);

                    $entry->forceFill([
                        'total_debit' => $amount,
                        'total_credit' => $amount,
                    ])->saveQuietly();
                });
            });
        } catch (\Throwable $e) {
            Log::error('AccountingService::createFromExpense falló', [
                'expense_id' => $expense->id,
                'error' => $e->getMessage(),
            ]);
            throw $e;
        }
    }

    public static function createFromReceivablePayment(ReceivablePayment $payment): JournalEntry
    {
        return TenantContext::run((string) $payment->business_id, function () use ($payment): JournalEntry {
            return DB::transaction(function () use ($payment): JournalEntry {
                return self::createSourceEntry(
                    (string) $payment->business_id,
                    'receivable_payment',
                    (string) $payment->id,
                    'Cobro CxC #'.$payment->id,
                    'Cobro de cuenta por cobrar',
                    (string) $payment->payment_date,
                    self::paymentAccountCode($payment->payment_method),
                    '1201',
                    (float) $payment->amount,
                    $payment->created_by ? (string) $payment->created_by : null,
                );
            });
        });
    }

    public static function createFromReceivablePaymentReversal(ReceivablePaymentReversal $reversal): JournalEntry
    {
        $payment = $reversal->payment;
        if (! $payment) {
            throw new \RuntimeException('La anulación requiere un cobro existente.');
        }

        return TenantContext::run((string) $reversal->business_id, function () use ($reversal, $payment): JournalEntry {
            return DB::transaction(function () use ($reversal, $payment): JournalEntry {
                return self::createSourceEntry(
                    (string) $reversal->business_id,
                    'receivable_payment_reversal',
                    (string) $reversal->id,
                    'Anulación cobro CxC #'.$payment->id,
                    'Reversión de cobro de cuenta por cobrar',
                    now()->toDateString(),
                    '1201',
                    self::paymentAccountCode($payment->payment_method),
                    (float) $payment->amount,
                    $reversal->created_by ? (string) $reversal->created_by : null,
                );
            });
        });
    }

    public static function createFromSaleReturn(SaleReturnOperation $operation, Sale $sale): JournalEntry
    {
        return TenantContext::run((string) $operation->business_id, function () use ($operation, $sale): JournalEntry {
            return DB::transaction(function () use ($operation, $sale): JournalEntry {
                $refund = round((float) $operation->refund_amount, 2);
                $tax = min($refund, round((float) $operation->tax_amount, 2));
                $net = round($refund - $tax, 2);
                $cost = round((float) $operation->inventory_cost, 2);
                $due = min($refund, round((float) $operation->refund_due_amount, 2));
                $lines = [];

                if ($net > 0) {
                    $lines[] = ['code' => '4101', 'debit' => $net, 'credit' => 0, 'description' => 'Devolución de ventas'];
                }
                if ($tax > 0) {
                    $lines[] = ['code' => '2201', 'debit' => $tax, 'credit' => 0, 'description' => 'Reversión de ITBIS'];
                }
                if ($cost > 0) {
                    $lines[] = ['code' => '1301', 'debit' => $cost, 'credit' => 0, 'description' => 'Ingreso de inventario devuelto'];
                    $lines[] = ['code' => '5101', 'debit' => 0, 'credit' => $cost, 'description' => 'Reversión de costo de ventas'];
                }

                $creditAmount = round($refund - $due, 2);
                if ($creditAmount > 0) {
                    $creditCode = $operation->refund_channel === 'receivable'
                        ? '1201'
                        : (($operation->refund_status === 'settled')
                            ? self::paymentAccountCode($operation->refund_method)
                            : '2301');
                    $lines[] = [
                        'code' => $creditCode,
                        'debit' => 0,
                        'credit' => $creditAmount,
                        'description' => 'Reembolso de venta',
                    ];
                }
                if ($due > 0) {
                    $lines[] = [
                        'code' => '2301',
                        'debit' => 0,
                        'credit' => $due,
                        'description' => 'Reembolso pendiente a cliente',
                    ];
                }

                if ($lines === []) {
                    throw new \RuntimeException('La devolución no tiene importes contables para registrar.');
                }

                return self::createMultiLineSourceEntry(
                    (string) $operation->business_id,
                    'sale_return',
                    (string) $operation->id,
                    'Devolución venta #'.($sale->sale_number ?: $sale->number ?: $sale->id),
                    'Reversión contable de devolución de venta',
                    now()->toDateString(),
                    $lines,
                    $operation->created_by ? (string) $operation->created_by : null,
                );
            });
        });
    }

    public static function createFromSaleRefundSettlement(SaleReturnOperation $operation): JournalEntry
    {
        return TenantContext::run((string) $operation->business_id, function () use ($operation): JournalEntry {
            return DB::transaction(function () use ($operation): JournalEntry {
                $amount = round((float) $operation->refund_due_amount, 2);

                return self::createMultiLineSourceEntry(
                    (string) $operation->business_id,
                    'sale_return_refund_settlement',
                    (string) $operation->id,
                    'Liquidación de reembolso #'.$operation->id,
                    'Liquidación de reembolso pendiente',
                    $operation->refund_settled_at?->toDateString() ?? now()->toDateString(),
                    [
                        ['code' => '2301', 'debit' => $amount, 'credit' => 0, 'description' => 'Liquidación de reembolso pendiente'],
                        [
                            'code' => self::paymentAccountCode($operation->refund_method),
                            'debit' => 0,
                            'credit' => $amount,
                            'description' => 'Pago de reembolso',
                        ],
                    ],
                    auth()->id() ? (string) auth()->id() : null,
                );
            });
        });
    }

    private static function createSourceEntry(
        string $businessId,
        string $sourceType,
        string $sourceId,
        string $reference,
        string $description,
        string $date,
        string $debitCode,
        string $creditCode,
        float $amount,
        ?string $createdBy,
    ): JournalEntry {
        $amount = round($amount, 2);
        if ($amount <= 0) {
            throw new \RuntimeException('No se puede contabilizar un importe no positivo.');
        }

        $existing = JournalEntry::withoutBusinessScope()->withTrashed()
            ->where('business_id', $businessId)
            ->where('source_type', $sourceType)
            ->where('source_id', $sourceId)
            ->lockForUpdate()
            ->first();
        if ($existing) {
            if ($existing->trashed() || ! $existing->isPosted() || ! $existing->isBalanced()
                || round((float) $existing->total_debit, 2) !== $amount
                || round((float) $existing->total_credit, 2) !== $amount
                || $existing->lines()->count() !== 2) {
                throw new \RuntimeException('El asiento financiero existente es inconsistente.');
            }

            return $existing;
        }

        $accounts = Account::withoutBusinessScope()
            ->where('business_id', $businessId)
            ->whereIn('code', [$debitCode, $creditCode])
            ->where('is_active', true)
            ->get()
            ->keyBy('code');
        $debitAccount = $accounts->get($debitCode);
        $creditAccount = $accounts->get($creditCode);
        if (! $debitAccount || ! $creditAccount) {
            throw new \RuntimeException('Faltan cuentas contables activas para registrar el movimiento de CxC.');
        }

        $entry = new JournalEntry([
            'date' => $date,
            'reference' => $reference,
            'description' => $description,
            'status' => JournalEntry::STATUS_POSTED,
            'total_debit' => $amount,
            'total_credit' => $amount,
            'created_by' => $createdBy,
        ]);
        $entry->forceFill([
            'business_id' => $businessId,
            'source_type' => $sourceType,
            'source_id' => $sourceId,
        ])->save();

        JournalEntryLine::create([
            'journal_entry_id' => $entry->id,
            'account_id' => $debitAccount->id,
            'debit' => $amount,
            'credit' => 0,
            'description' => $description,
        ]);
        JournalEntryLine::create([
            'journal_entry_id' => $entry->id,
            'account_id' => $creditAccount->id,
            'debit' => 0,
            'credit' => $amount,
            'description' => $description,
        ]);

        $entry->refresh();
        if (! $entry->isBalanced()) {
            throw new \RuntimeException('El asiento del movimiento de CxC no quedó balanceado.');
        }

        return $entry;
    }

    /**
     * @param  list<array{code:string,debit:float|int,credit:float|int,description:string}>  $lines
     */
    private static function createMultiLineSourceEntry(
        string $businessId,
        string $sourceType,
        string $sourceId,
        string $reference,
        string $description,
        string $date,
        array $lines,
        ?string $createdBy,
    ): JournalEntry {
        $existing = JournalEntry::withoutBusinessScope()->withTrashed()
            ->where('business_id', $businessId)
            ->where('source_type', $sourceType)
            ->where('source_id', $sourceId)
            ->lockForUpdate()
            ->first();
        if ($existing) {
            if ($existing->trashed() || ! $existing->isPosted() || ! $existing->isBalanced()
                || $existing->lines()->count() !== count($lines)
                || round((float) $existing->lines()->sum('debit'), 2) !== round((float) $existing->total_debit, 2)
                || round((float) $existing->lines()->sum('credit'), 2) !== round((float) $existing->total_credit, 2)) {
                throw new \RuntimeException('El asiento de devolución existente es inconsistente.');
            }

            return $existing;
        }

        $codes = array_values(array_unique(array_column($lines, 'code')));
        $accounts = Account::withoutBusinessScope()
            ->where('business_id', $businessId)
            ->whereIn('code', $codes)
            ->where('is_active', true)
            ->get()
            ->keyBy('code');
        if (in_array('2301', $codes, true) && ! $accounts->has('2301')) {
            $refundAccount = new Account([
                'code' => '2301',
                'name' => 'Reembolsos a Clientes por Pagar',
                'type' => 'liability',
                'is_system' => true,
                'is_active' => true,
            ]);
            $refundAccount->business_id = $businessId;
            $refundAccount->saveQuietly();
            $accounts->put('2301', $refundAccount);
        }

        $debit = round(array_sum(array_column($lines, 'debit')), 2);
        $credit = round(array_sum(array_column($lines, 'credit')), 2);
        if ($debit <= 0 || abs($debit - $credit) >= 0.01) {
            throw new \RuntimeException('El asiento de devolución no está balanceado.');
        }
        foreach ($codes as $code) {
            if (! $accounts->has($code)) {
                throw new \RuntimeException("Falta la cuenta contable {$code} para registrar la devolución.");
            }
        }

        $entry = new JournalEntry([
            'date' => $date,
            'reference' => $reference,
            'description' => $description,
            'status' => JournalEntry::STATUS_POSTED,
            'total_debit' => $debit,
            'total_credit' => $credit,
            'created_by' => $createdBy,
        ]);
        $entry->forceFill([
            'business_id' => $businessId,
            'source_type' => $sourceType,
            'source_id' => $sourceId,
        ])->save();

        foreach ($lines as $line) {
            JournalEntryLine::create([
                'journal_entry_id' => $entry->id,
                'account_id' => $accounts[$line['code']]->id,
                'debit' => $line['debit'],
                'credit' => $line['credit'],
                'description' => $line['description'],
            ]);
        }

        $entry->refresh();
        if (! $entry->isBalanced()) {
            throw new \RuntimeException('El asiento de devolución no quedó balanceado.');
        }

        return $entry;
    }

    protected static function saleIsCompleted(Sale $sale): bool
    {
        $status = $sale->status;

        if ($status instanceof SaleStatus) {
            return $status->isCompleted();
        }

        return in_array((string) $status, ['completada', 'completed'], true);
    }

    /**
     * Cuenta de cobro según método de pago de la venta.
     */
    protected static function paymentAccountCode(mixed $method): string
    {
        $value = $method instanceof PaymentMethod
            ? $method->value
            : (string) ($method ?? 'efectivo');

        $normalized = PaymentMethod::normalize($value);

        return match ($normalized) {
            PaymentMethod::Efectivo, PaymentMethod::Cash, PaymentMethod::Mixto, PaymentMethod::Mixed => '1101', // Caja
            PaymentMethod::Tarjeta, PaymentMethod::Card,
            PaymentMethod::Transferencia, PaymentMethod::Transfer,
            PaymentMethod::Cheque => '1102', // Banco
            PaymentMethod::Credito, PaymentMethod::Credit => '1201', // CxC
            default => '1101',
        };
    }

    /**
     * Cuenta de pago del gasto (Caja vs Banco).
     */
    protected static function expensePaymentAccountCode(mixed $method): string
    {
        $value = is_string($method) ? strtolower($method) : (string) ($method ?? 'efectivo');

        return match ($value) {
            'tarjeta', 'card', 'transferencia', 'transfer', 'banco', 'cheque' => '1102',
            default => '1101',
        };
    }
}
