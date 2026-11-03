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
use App\Models\Sale;
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
                    $totalCost = 0.0;
                    foreach ($sale->items as $item) {
                        $unitCost = (float) ($item->unit_cost ?? 0);
                        if ($unitCost <= 0) {
                            $unitCost = (float) ($item->product?->cost ?? 0);
                        }
                        $totalCost += $unitCost * (float) $item->quantity;
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
