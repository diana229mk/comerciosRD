<?php

namespace Tests\Feature;

use App\Enums\BusinessType;
use App\Enums\PaymentMethod;
use App\Enums\SaleStatus;
use App\Models\Account;
use App\Models\Business;
use App\Models\CashSession;
use App\Models\Expense;
use App\Models\InventoryMovement;
use App\Models\JournalEntry;
use App\Models\JournalEntryLine;
use App\Models\Payable;
use App\Models\Product;
use App\Models\ProductBatch;
use App\Models\Receivable;
use App\Models\Sale;
use App\Models\SaleItem;
use App\Models\SaleItemBatch;
use App\Models\User;
use App\Services\AccountingService;
use App\Services\ControlledDrugLedgerService;
use App\Services\JournalEntryService;
use App\Services\ReportsService;
use App\Services\SaleService;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Tests\TestCase;

class FinancialOperationsTest extends TestCase
{
    use DatabaseTransactions;

    public function test_partial_and_full_returns_reconcile_discount_tax_inventory_and_cash(): void
    {
        $fixture = $this->saleFixture(discount: 20, openCashSession: true);
        $sale = $fixture['sale'];
        $allocation = $fixture['allocation'];
        $product = $fixture['product'];
        $batch = $fixture['batch'];
        $session = $fixture['cash_session'];

        $first = app(SaleService::class)->returnFromAllocations(
            $sale,
            [$allocation->id => 1],
            true,
            false,
        );

        $this->assertSame('cash', $first['refund_channel']);
        $this->assertSame(106.20, $first['refund_amount']);
        $this->assertSame(9.0, (float) $batch->fresh()->quantity);
        $this->assertSame(9.0, (float) $product->fresh()->stock);
        $this->assertSame(106.20, (float) $session->fresh()->total_sales_cash);

        $second = app(SaleService::class)->returnFromAllocations(
            $sale->fresh(),
            [$allocation->id => 1],
            true,
            false,
        );

        $this->assertSame(106.20, $second['refund_amount']);
        $this->assertSame(212.40, $first['refund_amount'] + $second['refund_amount']);
        $this->assertSame(10.0, (float) $batch->fresh()->quantity);
        $this->assertSame(10.0, (float) $product->fresh()->stock);
        $this->assertSame(0.0, (float) $session->fresh()->total_sales_cash);
        $this->assertSame(2, InventoryMovement::query()
            ->where('reference_id', $sale->id)
            ->where('type', InventoryMovement::TYPE_RETURN_IN)
            ->count());

        try {
            app(SaleService::class)->returnFromAllocations(
                $sale->fresh(),
                [$allocation->id => 1],
                true,
                false,
            );
            $this->fail('A fully returned sale allocation must not be refundable again.');
        } catch (\RuntimeException $exception) {
            $this->assertStringContainsString('solo quedan 0', $exception->getMessage());
        }

        $this->assertSame(10.0, (float) $batch->fresh()->quantity);
        $this->assertSame(0.0, (float) $session->fresh()->total_sales_cash);
    }

    public function test_return_rejects_excess_and_negative_quantities_without_partial_changes(): void
    {
        $fixture = $this->saleFixture(openCashSession: true);
        $sale = $fixture['sale'];
        $allocation = $fixture['allocation'];
        $before = $this->returnState($fixture);

        foreach ([3, -1] as $quantity) {
            try {
                app(SaleService::class)->returnFromAllocations(
                    $sale,
                    [$allocation->id => $quantity],
                    true,
                    false,
                );
                $this->fail('Invalid return quantities must be rejected.');
            } catch (\InvalidArgumentException|\RuntimeException) {
                $this->assertSame($before, $this->returnState($fixture));
            }
        }

        try {
            app(SaleService::class)->returnFromAllocations(
                $sale,
                ['missing-allocation' => 1],
                true,
                false,
            );
            $this->fail('An allocation outside the sale must be rejected.');
        } catch (\InvalidArgumentException) {
            $this->assertSame($before, $this->returnState($fixture));
        }
    }

    public function test_user_without_refund_permission_cannot_change_inventory_or_cash(): void
    {
        $fixture = $this->saleFixture(openCashSession: true);
        $administrator = User::factory()->create();
        $supervisor = User::factory()->create();
        $cashier = User::factory()->create();
        foreach ([[$administrator, 'admin'], [$supervisor, 'supervisor'], [$cashier, 'cashier']] as [$member, $role]) {
            $member->businesses()->attach($fixture['business']->id, [
                'role' => $role,
                'is_active' => true,
            ]);
        }
        $this->setTenant($fixture['business'], $cashier);
        $saleService = app(SaleService::class);
        $this->assertTrue($saleService->canReturn($fixture['sale'], $fixture['user']));
        $this->assertTrue($saleService->canReturn($fixture['sale'], $administrator));
        $this->assertTrue($saleService->canReturn($fixture['sale'], $supervisor));
        $this->assertFalse($saleService->canReturn($fixture['sale'], $cashier));
        $before = $this->returnState($fixture);

        try {
            app(SaleService::class)->returnFromAllocations(
                $fixture['sale'],
                [$fixture['allocation']->id => 1],
                true,
                false,
            );
            $this->fail('Cashiers do not have the sales.refund permission.');
        } catch (HttpException $exception) {
            $this->assertSame(403, $exception->getStatusCode());
        }

        $this->assertSame($before, $this->returnState($fixture));
    }

    public function test_user_from_another_business_cannot_return_the_sale(): void
    {
        $fixture = $this->saleFixture(openCashSession: true);
        $other = $this->businessFixture('Negocio sin acceso');
        $this->setTenant($other['business'], $other['user']);
        $before = $this->returnState($fixture);

        try {
            app(SaleService::class)->returnFromAllocations(
                $fixture['sale'],
                [$fixture['allocation']->id => 1],
                true,
                false,
            );
            $this->fail('A sale from another tenant must not be returned.');
        } catch (HttpException $exception) {
            $this->assertSame(404, $exception->getStatusCode());
        }

        $this->assertSame($before, $this->returnState($fixture));
    }

    public function test_return_larger_than_amount_paid_is_rejected_before_inventory_or_cash_changes(): void
    {
        $fixture = $this->saleFixture(discount: 20, openCashSession: true);
        $fixture['sale']->forceFill(['amount_paid' => 100])->saveQuietly();
        $before = $this->returnState($fixture);

        try {
            app(SaleService::class)->returnFromAllocations(
                $fixture['sale'],
                [$fixture['allocation']->id => 1],
                true,
                false,
            );
            $this->fail('A return may not exceed the amount paid.');
        } catch (\RuntimeException $exception) {
            $this->assertStringContainsString('superar el monto pagado', $exception->getMessage());
        }

        $this->assertSame($before, $this->returnState($fixture));
    }

    public function test_inventory_and_cash_changes_roll_back_if_a_return_side_effect_fails(): void
    {
        $fixture = $this->saleFixture(openCashSession: true);
        $before = $this->returnState($fixture);
        $ledger = $this->mock(ControlledDrugLedgerService::class);
        $ledger->shouldReceive('recordSaleReturn')->once()->andThrow(new \RuntimeException('ledger unavailable'));

        try {
            app(SaleService::class)->returnFromAllocations(
                $fixture['sale'],
                [$fixture['allocation']->id => 1],
                true,
                false,
            );
            $this->fail('The failure must abort the return transaction.');
        } catch (\RuntimeException $exception) {
            $this->assertSame('ledger unavailable', $exception->getMessage());
        }

        $this->assertSame($before, $this->returnState($fixture));
    }

    public function test_credit_return_reduces_only_the_matching_receivable(): void
    {
        $fixture = $this->saleFixture(paymentMethod: PaymentMethod::Credito->value);
        $sale = $fixture['sale'];
        $receivable = new Receivable;
        $receivable->forceFill([
            'id' => (string) Str::uuid(),
            'business_id' => $fixture['business']->id,
            'reference' => 'Venta '.$sale->sale_number,
            'description' => 'Crédito de prueba',
            'amount' => 236.0,
            'paid_amount' => 0,
            'balance' => 236.0,
            'due_date' => now()->addDays(7)->toDateString(),
            'status' => 'pendiente',
        ])->saveQuietly();

        $result = app(SaleService::class)->returnFromAllocations(
            $sale,
            [$fixture['allocation']->id => 1],
            true,
            false,
        );

        $this->assertSame('receivable', $result['refund_channel']);
        $this->assertSame(118.0, $result['refund_amount']);
        $this->assertSame(118.0, (float) $receivable->fresh()->amount);
        $this->assertSame(118.0, (float) $receivable->fresh()->balance);
    }

    public function test_cash_return_without_an_open_session_is_marked_for_manual_refund(): void
    {
        $fixture = $this->saleFixture();

        $result = app(SaleService::class)->returnFromAllocations(
            $fixture['sale'],
            [$fixture['allocation']->id => 1],
            true,
            false,
        );

        $this->assertSame('cash_failed', $result['refund_channel']);
        $this->assertStringContainsString('registra el reembolso manualmente', $result['refund_note']);
        $this->assertSame(9.0, (float) $fixture['batch']->fresh()->quantity);
        $this->assertSame(9.0, (float) $fixture['product']->fresh()->stock);
    }

    public function test_receivable_and_payable_payments_reject_invalid_amounts_and_update_balances(): void
    {
        $tenant = $this->businessFixture('Cuentas de prueba');
        $this->setTenant($tenant['business'], $tenant['user']);

        $receivable = new Receivable;
        $receivable->forceFill([
            'id' => (string) Str::uuid(),
            'business_id' => $tenant['business']->id,
            'description' => 'Venta a crédito ficticia',
            'amount' => 100,
            'paid_amount' => 0,
            'balance' => 100,
            'due_date' => now()->addDays(10)->toDateString(),
            'status' => 'pendiente',
        ])->saveQuietly();

        $payable = new Payable;
        $payable->forceFill([
            'id' => (string) Str::uuid(),
            'business_id' => $tenant['business']->id,
            'supplier_name' => 'Proveedor ficticio',
            'description' => 'Compra a crédito ficticia',
            'amount' => 80,
            'paid_amount' => 0,
            'balance' => 80,
            'due_date' => now()->addDays(10)->toDateString(),
            'status' => 'pendiente',
        ])->saveQuietly();

        $receivable->registerPayment(25, 'transferencia');
        $payable->registerPayment(20, 'transferencia');

        $this->assertSame(25.0, (float) $receivable->fresh()->paid_amount);
        $this->assertSame(75.0, (float) $receivable->fresh()->balance);
        $this->assertSame(20.0, (float) $payable->fresh()->paid_amount);
        $this->assertSame(60.0, (float) $payable->fresh()->balance);

        foreach ([
            fn () => $receivable->fresh()->registerPayment(76, 'transferencia'),
            fn () => $payable->fresh()->registerPayment(61, 'transferencia'),
            fn () => $receivable->fresh()->registerPayment(-1, 'transferencia'),
            fn () => $payable->fresh()->registerPayment(0, 'transferencia'),
        ] as $invalidPayment) {
            try {
                $invalidPayment();
                $this->fail('Invalid account payments must be rejected.');
            } catch (\InvalidArgumentException) {
                $this->assertSame(1, $receivable->payments()->count());
                $this->assertSame(1, $payable->payments()->count());
            }
        }
    }

    public function test_accounting_entries_balance_and_are_sequentially_idempotent_per_tenant(): void
    {
        $fixture = $this->saleFixture();
        AccountingService::createDefaultAccounts($fixture['business']);
        AccountingService::createDefaultAccounts($fixture['business']);

        $sale = $fixture['sale'];
        AccountingService::createFromSale($sale);
        AccountingService::createFromSale($sale);

        $entry = JournalEntry::query()
            ->where('business_id', $fixture['business']->id)
            ->where('reference', 'Venta #'.$sale->sale_number)
            ->firstOrFail();

        $this->assertSame(1, JournalEntry::query()
            ->where('business_id', $fixture['business']->id)
            ->where('reference', 'Venta #'.$sale->sale_number)
            ->count());
        $this->assertSame(296.0, (float) $entry->total_debit);
        $this->assertSame(296.0, (float) $entry->total_credit);
        $this->assertTrue($entry->isBalanced());
        $this->assertSame(14, Account::query()
            ->where('business_id', $fixture['business']->id)
            ->count());

        $expense = new Expense;
        $expense->forceFill([
            'id' => (string) Str::uuid(),
            'business_id' => $fixture['business']->id,
            'category' => 'otros',
            'description' => 'Suministros de prueba',
            'amount' => 25.50,
            'payment_method' => 'efectivo',
            'expense_date' => now()->toDateString(),
            'is_paid' => true,
        ])->saveQuietly();

        AccountingService::createFromExpense($expense);
        AccountingService::createFromExpense($expense);

        $expenseEntry = JournalEntry::query()
            ->where('business_id', $fixture['business']->id)
            ->where('reference', 'Gasto #'.$expense->id)
            ->firstOrFail();

        $this->assertSame(25.50, (float) $expenseEntry->total_debit);
        $this->assertSame(25.50, (float) $expenseEntry->total_credit);
        $this->assertSame(2, JournalEntry::query()
            ->where('business_id', $fixture['business']->id)
            ->count());
        $this->assertSame(7, JournalEntryLine::query()
            ->whereIn('journal_entry_id', [$entry->id, $expenseEntry->id])
            ->count());
    }

    public function test_journal_entry_publishing_requires_a_balanced_multi_line_entry(): void
    {
        $fixture = $this->saleFixture();
        AccountingService::createDefaultAccounts($fixture['business']);
        $accounts = Account::query()
            ->where('business_id', $fixture['business']->id)
            ->whereIn('code', ['1101', '4101'])
            ->get()
            ->keyBy('code');

        $balanced = $this->draftEntry($fixture['business']);
        JournalEntryLine::create([
            'journal_entry_id' => $balanced->id,
            'account_id' => $accounts['1101']->id,
            'debit' => 50,
            'credit' => 0,
        ]);
        JournalEntryLine::create([
            'journal_entry_id' => $balanced->id,
            'account_id' => $accounts['4101']->id,
            'debit' => 0,
            'credit' => 50,
        ]);

        $published = app(JournalEntryService::class)->publish($balanced);
        $this->assertSame(JournalEntry::STATUS_POSTED, $published->status);
        $this->assertTrue($published->isBalanced());

        $incomplete = $this->draftEntry($fixture['business']);
        JournalEntryLine::create([
            'journal_entry_id' => $incomplete->id,
            'account_id' => $accounts['1101']->id,
            'debit' => 50,
            'credit' => 0,
        ]);

        try {
            app(JournalEntryService::class)->publish($incomplete);
            $this->fail('An unbalanced journal entry must not be published.');
        } catch (\RuntimeException $exception) {
            $this->assertStringContainsString('al menos 2 líneas', $exception->getMessage());
        }

        $this->assertSame(JournalEntry::STATUS_DRAFT, $incomplete->fresh()->status);
        $this->assertSame(50.0, (float) $incomplete->fresh()->total_debit);
        $this->assertSame(0.0, (float) $incomplete->fresh()->total_credit);
    }

    public function test_reports_match_independent_period_totals_and_exclude_other_tenants(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-10-24 12:00:00'));

        try {
            $fixture = $this->saleFixture(discount: 20, soldAt: '2026-10-05 10:00:00');
            $inside = $fixture['sale'];
            $this->setTenant($fixture['business'], $fixture['user']);
            $this->additionalSale($fixture['business'], $fixture['product'], '2026-10-20 10:00:00', 118, 100, 30);
            $this->additionalSale($fixture['business'], $fixture['product'], '2026-11-01 10:00:00', 59, 50, 15);
            $this->createOpenBalances($fixture['business'], 37.25, 41.10);

            $other = $this->businessFixture('Otro reporte');
            $this->additionalSale($other['business'], $this->productFor($other['business']), '2026-10-10 10:00:00', 999, 900, 450);

            $this->setTenant($fixture['business'], $fixture['user']);
            $report = app(ReportsService::class)->build(
                Carbon::parse('2026-10-01'),
                Carbon::parse('2026-10-31'),
            );

            $this->assertSame(2, $report['sales_summary']['count']);
            $this->assertSame(330.40, $report['sales_summary']['total']);
            $this->assertSame(165.20, $report['sales_summary']['avg']);
            $this->assertSame(50.40, $report['sales_summary']['tax']);
            $this->assertSame(300.0, $report['sales_summary']['subtotal']);
            $this->assertSame(20.0, $report['sales_summary']['discount']);
            $this->assertSame(31, count($report['sales_by_day']));
            $this->assertSame(1, collect($report['sales_by_day'])->firstWhere('date', '2026-10-05')['count']);
            $this->assertSame(118.0, collect($report['sales_by_day'])->firstWhere('date', '2026-10-20')['total']);
            $this->assertSame(280.0, $report['margins']['revenue']);
            $this->assertSame(90.0, $report['margins']['cost']);
            $this->assertSame(190.0, $report['margins']['profit']);
            $this->assertSame(67.9, $report['margins']['margin_pct']);
            $this->assertSame(280.0, $report['top_products'][0]['revenue']);
            $this->assertSame(37.25, $report['receivables']['open_balance']);
            $this->assertSame(41.10, $report['payables']['open_balance']);
            $this->assertNotSame($inside->business_id, $other['business']->id);
        } finally {
            Carbon::setTestNow();
        }
    }

    /**
     * @return array{
     *     user:User,business:Business,product:Product,sale:Sale,item:SaleItem,
     *     batch:ProductBatch,allocation:SaleItemBatch,cash_session:?CashSession
     * }
     */
    private function saleFixture(
        float $discount = 0,
        string $paymentMethod = 'efectivo',
        bool $openCashSession = false,
        ?string $soldAt = null,
    ): array {
        $tenant = $this->businessFixture('Operaciones financieras');
        $business = $tenant['business'];
        $user = $tenant['user'];
        $this->setTenant($business, $user);

        $product = $this->productFor($business);
        $saleNumber = 'VTA-'.strtoupper(Str::random(12));
        $subtotal = 200.0;
        $taxRate = 18.0;
        $total = round(($subtotal - $discount) * (1 + $taxRate / 100), 2);
        $now = $soldAt ? Carbon::parse($soldAt) : now();
        $sale = new Sale;
        $sale->forceFill([
            'id' => (string) Str::uuid(),
            'business_id' => $business->id,
            'sale_number' => $saleNumber,
            'number' => $saleNumber,
            'subtotal' => $subtotal,
            'discount' => $discount,
            'tax_rate' => $taxRate,
            'tax' => round(($subtotal - $discount) * $taxRate / 100, 2),
            'total' => $total,
            'payment_method' => $paymentMethod,
            'amount_paid' => PaymentMethod::isCreditValue($paymentMethod) ? 0 : $total,
            'status' => SaleStatus::Completada->value,
            'created_by' => $user->id,
            'user_id' => $user->id,
            'sale_date' => $now,
            'sold_at' => $now,
        ])->saveQuietly();

        $item = new SaleItem;
        $item->forceFill([
            'id' => (string) Str::uuid(),
            'business_id' => $business->id,
            'sale_id' => $sale->id,
            'product_id' => $product->id,
            'product_name' => $product->name,
            'sku' => $product->sku,
            'quantity' => 2,
            'unit_price' => 100,
            'unit_cost' => 30,
            'subtotal' => $subtotal,
            'line_total' => $subtotal,
        ])->saveQuietly();

        $batch = new ProductBatch;
        $batch->forceFill([
            'id' => (string) Str::uuid(),
            'business_id' => $business->id,
            'product_id' => $product->id,
            'code' => 'BATCH-TEST',
            'quantity' => 8,
            'initial_quantity' => 10,
            'cost' => 30,
            'is_blocked' => false,
        ])->saveQuietly();

        $allocation = new SaleItemBatch;
        $allocation->forceFill([
            'id' => (string) Str::uuid(),
            'business_id' => $business->id,
            'sale_item_id' => $item->id,
            'sale_id' => $sale->id,
            'batch_id' => $batch->id,
            'quantity' => 2,
            'returned_quantity' => 0,
        ])->saveQuietly();

        $session = null;
        if ($openCashSession) {
            $session = new CashSession;
            $session->forceFill([
                'id' => (string) Str::uuid(),
                'business_id' => $business->id,
                'user_id' => $user->id,
                'opening_amount' => 0,
                'total_sales_cash' => $total,
                'total_expenses_cash' => 0,
                'status' => CashSession::STATUS_OPEN,
                'opened_at' => now()->subHour(),
            ])->saveQuietly();
        }

        return compact('user', 'business', 'product', 'sale', 'item', 'batch', 'allocation', 'session')
            + ['cash_session' => $session];
    }

    /**
     * @return array{user:User,business:Business}
     */
    private function businessFixture(string $name): array
    {
        $user = User::factory()->create();
        $business = Business::create([
            'name' => $name,
            'type' => BusinessType::Colmado,
            'owner_id' => $user->id,
            'is_active' => true,
        ]);
        $user->businesses()->attach($business->id, [
            'role' => 'owner',
            'is_active' => true,
        ]);

        return compact('user', 'business');
    }

    private function productFor(Business $business): Product
    {
        $product = new Product;
        $product->forceFill([
            'id' => (string) Str::uuid(),
            'business_id' => $business->id,
            'name' => 'Producto de prueba',
            'sku' => strtoupper(Str::random(12)),
            'price' => 100,
            'cost' => 30,
            'stock' => 8,
            'min_stock' => 0,
            'track_stock' => true,
            'inventory_behavior' => 'deduct_on_sale',
            'is_active' => true,
        ])->saveQuietly();

        return $product;
    }

    private function additionalSale(
        Business $business,
        Product $product,
        string $date,
        float $total,
        float $subtotal,
        float $cost,
    ): Sale {
        $saleNumber = 'VTA-'.strtoupper(Str::random(12));
        $sale = new Sale;
        $sale->forceFill([
            'id' => (string) Str::uuid(),
            'business_id' => $business->id,
            'sale_number' => $saleNumber,
            'number' => $saleNumber,
            'subtotal' => $subtotal,
            'tax_rate' => 18,
            'tax' => round($total - $subtotal, 2),
            'total' => $total,
            'payment_method' => PaymentMethod::Efectivo->value,
            'amount_paid' => $total,
            'status' => SaleStatus::Completada->value,
            'sale_date' => Carbon::parse($date),
            'sold_at' => Carbon::parse($date),
        ])->saveQuietly();

        $item = new SaleItem;
        $item->forceFill([
            'id' => (string) Str::uuid(),
            'business_id' => $business->id,
            'sale_id' => $sale->id,
            'product_id' => $product->id,
            'product_name' => $product->name,
            'quantity' => 1,
            'unit_price' => $subtotal,
            'unit_cost' => $cost,
            'subtotal' => $subtotal,
            'line_total' => $subtotal,
        ])->saveQuietly();

        return $sale;
    }

    private function setTenant(Business $business, User $user): void
    {
        $this->actingAs($user);
        app()->instance('currentBusinessId', (string) $business->id);
        app()->instance('currentBusiness', $business);
        session()->put('current_business_id', (string) $business->id);
    }

    private function createOpenBalances(Business $business, float $receivableBalance, float $payableBalance): void
    {
        $receivable = new Receivable;
        $receivable->forceFill([
            'id' => (string) Str::uuid(),
            'business_id' => $business->id,
            'description' => 'Saldo de prueba',
            'amount' => $receivableBalance,
            'paid_amount' => 0,
            'balance' => $receivableBalance,
            'due_date' => now()->addDays(10)->toDateString(),
            'status' => 'pendiente',
        ])->saveQuietly();

        $payable = new Payable;
        $payable->forceFill([
            'id' => (string) Str::uuid(),
            'business_id' => $business->id,
            'supplier_name' => 'Proveedor de prueba',
            'description' => 'Saldo de prueba',
            'amount' => $payableBalance,
            'paid_amount' => 0,
            'balance' => $payableBalance,
            'due_date' => now()->addDays(10)->toDateString(),
            'status' => 'pendiente',
        ])->saveQuietly();
    }

    private function draftEntry(Business $business): JournalEntry
    {
        $entry = new JournalEntry;
        $entry->forceFill([
            'id' => (string) Str::uuid(),
            'business_id' => $business->id,
            'entry_number' => 'ASI-'.strtoupper(Str::random(12)),
            'date' => now()->toDateString(),
            'reference' => 'Prueba '.Str::random(6),
            'description' => 'Asiento de validación',
            'status' => JournalEntry::STATUS_DRAFT,
            'total_debit' => 0,
            'total_credit' => 0,
        ])->saveQuietly();

        return $entry;
    }

    /**
     * @param  array<string, mixed>  $fixture
     * @return array{allocation_returned:float,batch_quantity:float,product_stock:float,movements:int,cash_total:float}
     */
    private function returnState(array $fixture): array
    {
        return [
            'allocation_returned' => (float) $fixture['allocation']->fresh()->returned_quantity,
            'batch_quantity' => (float) $fixture['batch']->fresh()->quantity,
            'product_stock' => (float) $fixture['product']->fresh()->stock,
            'movements' => InventoryMovement::query()
                ->where('reference_id', $fixture['sale']->id)
                ->where('type', InventoryMovement::TYPE_RETURN_IN)
                ->count(),
            'cash_total' => (float) ($fixture['cash_session']?->fresh()->total_sales_cash ?? 0),
        ];
    }
}
