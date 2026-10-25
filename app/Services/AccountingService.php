<?php

namespace App\Services;

use App\Enums\PaymentMethod;
use App\Enums\SaleStatus;
use App\Models\Account;
use App\Models\Business;
use App\Models\Expense;
use App\Models\JournalEntry;
use App\Models\JournalEntryLine;
use App\Models\Sale;
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
            $model->save();
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
            if (! self::saleIsCompleted($sale)) {
                return;
            }

            $saleNumber = $sale->sale_number ?: $sale->number ?: $sale->id;
            $reference = 'Venta #'.$saleNumber;

            $exists = JournalEntry::withoutBusinessScope()
                ->where('business_id', $sale->business_id)
                ->where('reference', $reference)
                ->exists();

            if ($exists) {
                return;
            }

            $sale->loadMissing(['items.product']);

            DB::transaction(function () use ($sale, $reference, $saleNumber): void {
                $accounts = Account::withoutBusinessScope()
                    ->where('business_id', $sale->business_id)
                    ->where('is_system', true)
                    ->get()
                    ->keyBy('code');

                // Si no hay plan de cuentas, no fallar la venta
                if ($accounts->isEmpty()) {
                    Log::warning('AccountingService: sin cuentas del sistema para la venta', [
                        'sale_id' => $sale->id,
                        'business_id' => $sale->business_id,
                    ]);

                    return;
                }

                $paymentAccountCode = self::paymentAccountCode($sale->payment_method);
                $paymentAccount = $accounts[$paymentAccountCode] ?? null;
                $salesAccount = $accounts['4101'] ?? null;
                $taxAccount = $accounts['2201'] ?? null;
                $cogsAccount = $accounts['5101'] ?? null;
                $inventoryAccount = $accounts['1301'] ?? null;

                if (! $paymentAccount || ! $salesAccount) {
                    Log::warning('AccountingService: faltan cuentas Caja/Ventas', [
                        'sale_id' => $sale->id,
                        'business_id' => $sale->business_id,
                    ]);

                    return;
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

                if ($totalCost > 0 && $cogsAccount && $inventoryAccount) {
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
            });
        } catch (\Throwable $e) {
            Log::error('AccountingService::createFromSale falló', [
                'sale_id' => $sale->id,
                'error' => $e->getMessage(),
            ]);
        }
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
            $reference = 'Gasto #'.$expense->id;

            $exists = JournalEntry::withoutBusinessScope()
                ->where('business_id', $expense->business_id)
                ->where('reference', $reference)
                ->exists();

            if ($exists) {
                return;
            }

            $amount = round((float) $expense->amount, 2);
            if ($amount <= 0) {
                return;
            }

            DB::transaction(function () use ($expense, $reference, $amount): void {
                $accounts = Account::withoutBusinessScope()
                    ->where('business_id', $expense->business_id)
                    ->where('is_system', true)
                    ->get()
                    ->keyBy('code');

                $expenseAccount = $accounts['6101'] ?? null; // Gastos Operativos
                $paymentCode = self::expensePaymentAccountCode($expense->payment_method);
                $cashAccount = $accounts[$paymentCode] ?? $accounts['1101'] ?? null;

                if (! $expenseAccount || ! $cashAccount) {
                    Log::warning('AccountingService: faltan cuentas para gasto', [
                        'expense_id' => $expense->id,
                        'business_id' => $expense->business_id,
                    ]);

                    return;
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
        } catch (\Throwable $e) {
            Log::error('AccountingService::createFromExpense falló', [
                'expense_id' => $expense->id,
                'error' => $e->getMessage(),
            ]);
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
