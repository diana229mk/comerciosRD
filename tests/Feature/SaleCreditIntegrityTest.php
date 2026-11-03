<?php

namespace Tests\Feature;

use App\Enums\BusinessType;
use App\Filament\Resources\ReceivableResource\Pages\EditReceivable;
use App\Filament\Resources\SaleResource\Pages\CreateSale;
use App\Jobs\PostSaleAccountingJob;
use App\Models\AccountingOutbox;
use App\Models\Branch;
use App\Models\Business;
use App\Models\Customer;
use App\Models\JournalEntryLine;
use App\Models\Product;
use App\Models\Receivable;
use App\Models\Sale;
use App\Models\SaleItem;
use App\Models\User;
use App\Observers\SaleObserver;
use App\Services\AccountingService;
use App\Services\SaleCreditService;
use App\Services\SaleFinancialIntegrity;
use App\Services\SaleService;
use App\Support\TenantContext;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Livewire\Livewire;
use PHPUnit\Framework\Attributes\DataProvider;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;
use Tests\TestCase;

class SaleCreditIntegrityTest extends TestCase
{
    use DatabaseTransactions;

    protected function setUp(): void
    {
        parent::setUp();
        config(['scale.post_sale_queue' => true, 'ecf.enabled' => false]);
        Queue::fake()->except(PostSaleAccountingJob::class);
    }

    public function test_credit_creates_one_linked_receivable_and_replay_survives_catalog_changes(): void
    {
        $fixture = $this->context();
        $command = $this->command($fixture);
        $sale = app(SaleService::class)->create($command);
        $receivable = app(SaleCreditService::class)->assertReceivable($sale);
        $this->assertSame((string) $fixture['customer']->id, (string) $receivable->customer_id);
        $this->assertSame('118.00', $receivable->original_amount);
        $this->assertSame('118.00', $receivable->balance);
        $this->assertSame('0.00', $receivable->paid_amount);
        $this->assertNotNull($sale->fresh()->credit_effects_at);
        $before = $this->state($fixture);
        $fixture['product']->forceFill(['stock' => 0, 'price' => 999])->saveQuietly();
        $retry = app(SaleService::class)->create($command);
        $this->assertSame((string) $sale->id, (string) $retry->id);
        $after = $this->state($fixture);
        unset($before['stock'], $after['stock']);
        $this->assertSame($before, $after);
        $this->assertSame(1, Receivable::withoutBusinessScope()->where('sale_id', $sale->id)->count());
    }

    public function test_same_key_with_another_payload_is_rejected_without_effects(): void
    {
        $fixture = $this->context();
        $command = $this->command($fixture);
        app(SaleService::class)->create($command);
        $before = $this->state($fixture);
        $command['items'][0]['quantity'] = 2;
        $this->rejectsWithoutEffects($fixture, $before, fn () => app(SaleService::class)->create($command));
    }

    public function test_a_failed_save_can_be_retried_with_the_original_request_key(): void
    {
        $fixture = $this->context();
        $command = $this->command($fixture);
        $before = $this->state($fixture);
        $reject = true;
        Receivable::creating(function () use (&$reject) {
            return $reject ? false : null;
        });
        $this->rejectsWithoutEffects($fixture, $before, fn () => app(SaleService::class)->create($command));
        $reject = false;
        $sale = app(SaleService::class)->create($command);
        $this->assertSame(1, Receivable::withoutBusinessScope()->where('sale_id', $sale->id)->count());
        $this->assertSame(1, DB::table('sale_checkout_requests')->where('request_key', $command['idempotency_key'])->count());
    }

    public function test_an_outer_rollback_also_removes_the_request_and_all_credit_effects(): void
    {
        $fixture = $this->context();
        $before = $this->state($fixture);
        DB::beginTransaction();
        try {
            app(SaleService::class)->create($this->command($fixture));
        } finally {
            DB::rollBack();
        }
        $this->assertSame($before, $this->state($fixture));
        Queue::assertNothingPushed();
    }

    public function test_inline_accounting_commits_only_with_the_credit_and_its_receivable(): void
    {
        $fixture = $this->context();
        AccountingService::createDefaultAccounts($fixture['business']);
        config(['scale.post_sale_queue' => false]);
        $command = $this->command($fixture);
        $sale = app(SaleService::class)->create($command);
        app(SaleService::class)->create($command);
        $this->assertSame('118.00', app(SaleCreditService::class)->assertReceivable($sale)->original_amount);
        $this->assertSame(1, DB::table('journal_entries')->count());
        $this->assertSame('processed', AccountingOutbox::withoutBusinessScope()->where('sale_id', $sale->id)->sole()->status);
    }

    #[DataProvider('invalidCreditInputs')]
    public function test_invalid_credit_is_rejected_without_partial_confirmation(string $case): void
    {
        $fixture = $this->context();
        $command = $this->command($fixture);
        if ($case === 'disabled') {
            $fixture['business']->forceFill(['enabled_modules' => ['sales', 'customers']])->save();
        } elseif ($case === 'foreign') {
            $other = Business::withoutEvents(fn () => Business::create([
                'name' => 'Otra empresa F2',
                'slug' => 'f2-other-'.Str::uuid(),
                'type' => BusinessType::Colmado,
                'owner_id' => $fixture['user']->id, 'is_active' => true,
            ]));
            $foreign = TenantContext::run((string) $other->id,
                fn () => Customer::create(['name' => 'Cliente ajeno', 'is_active' => true]));
            $command['customer_id'] = $foreign->id;
        } elseif ($case === 'missing-key') {
            unset($command['idempotency_key']);
        } elseif ($case === 'inactive') {
            $fixture['customer']->update(['is_active' => false]);
        } elseif ($case === 'deleted') {
            $fixture['customer']->delete();
        } else {
            $command['customer_id'] = $case === 'missing' ? null : (string) Str::uuid();
        }
        $before = $this->state($fixture);
        try {
            app(SaleService::class)->create($command);
            $this->fail('Credit must fail closed.');
        } catch (ValidationException|\InvalidArgumentException) {
            $this->assertSame($before, $this->state($fixture));
        }
    }

    public static function invalidCreditInputs(): array
    {
        return array_map(fn ($case) => [$case], ['disabled', 'foreign', 'missing', 'unknown', 'missing-key', 'inactive', 'deleted']);
    }

    #[DataProvider('creditFailures')]
    public function test_failure_rolls_back_header_lines_receivable_stock_summary_and_outbox(string $failure): void
    {
        $fixture = $this->context();
        $before = $this->state($fixture);
        if ($failure === 'header-false') {
            Sale::creating(fn () => false);
        } elseif ($failure === 'line-false') {
            SaleItem::creating(fn () => false);
        } elseif ($failure === 'save-false') {
            Receivable::creating(fn () => false);
        } elseif ($failure === 'sql-fk') {
            Receivable::creating(function (Receivable $receivable): void {
                $receivable->customer_id = (string) Str::uuid();
            });
        } elseif ($failure === 'after-receivable') {
            DB::listen(function (QueryExecuted $query): void {
                if (str_starts_with(strtolower(ltrim($query->sql)), 'insert') && str_contains($query->sql, 'sales_daily')) {
                    throw new \RuntimeException('F2 after receivable and summary');
                }
            });
        } elseif ($failure === 'outbox-false') {
            AccountingOutbox::creating(fn () => false);
        } else {
            config(['scale.post_sale_queue' => false]);
            AccountingService::createDefaultAccounts($fixture['business']);
            JournalEntryLine::creating(fn () => throw new \RuntimeException('F2 inline ledger failure'));
        }
        try {
            app(SaleService::class)->create($this->command($fixture));
            $this->fail('Every failure must roll back the credit.');
        } catch (QueryException $exception) {
            $this->assertSame('sql-fk', $failure);
            $this->assertSame(1452, (int) $exception->errorInfo[1]);
        } catch (ValidationException|\RuntimeException $exception) {
            $this->assertNotSame('sql-fk', $failure, 'The FK test must produce a real SQL exception.');
        }
        $this->assertSame($before, $this->state($fixture));
        Queue::assertNothingPushed();
    }

    public static function creditFailures(): array
    {
        return array_map(fn ($case) => [$case],
            ['header-false', 'line-false', 'save-false', 'sql-fk', 'after-receivable', 'outbox-false', 'inline-ledger']);
    }

    public function test_ordinary_and_quiet_model_creation_cannot_bypass_the_credit_coordinator(): void
    {
        $fixture = $this->context();
        foreach (['save', 'quiet', 'without-events'] as $operation) {
            $sale = (new Sale)->forceFill([
                'business_id' => $fixture['business']->id, 'customer_id' => $fixture['customer']->id,
                'payment_method' => 'credito', 'total' => 118,
            ]);
            $before = $this->state($fixture);
            $this->rejectsWithoutEffects($fixture, $before, fn () => match ($operation) {
                'save' => $sale->save(),
                'quiet' => $sale->saveQuietly(),
                default => Sale::withoutEvents(fn () => $sale->save()),
            });
        }
    }

    public function test_a_construction_callback_cannot_omit_the_receivable_or_its_event(): void
    {
        $fixture = $this->context();
        $sale = (new Sale)->forceFill([
            'business_id' => $fixture['business']->id, 'customer_id' => $fixture['customer']->id,
            'payment_method' => 'credit', 'status' => 'completed', 'amount_paid' => 0,
            'subtotal' => 100, 'tax_rate' => 18, 'tax' => 18, 'total' => 118,
        ]);
        app(SaleFinancialIntegrity::class)->construct($sale, function (Sale $sale) use ($fixture): void {
            $sale->items()->create(['product_id' => $fixture['product']->id, 'quantity' => 1, 'unit_price' => 100]);
        });
        $this->assertSame(1, Receivable::withoutBusinessScope()->where('sale_id', $sale->id)->count());
        $this->assertSame(1, AccountingOutbox::withoutBusinessScope()->where('sale_id', $sale->id)->count());
        $before = $this->state($fixture);
        app(SaleObserver::class)->processCompletedSale($sale);
        $this->assertSame($before, $this->state($fixture));
    }

    #[DataProvider('linkedWrites')]
    public function test_linked_receivable_cannot_be_deleted_modified_or_relinked(string $operation): void
    {
        $fixture = $this->context();
        $sale = app(SaleService::class)->create($this->command($fixture));
        $receivable = app(SaleCreditService::class)->assertReceivable($sale);
        $before = $receivable->getAttributes();
        $write = fn () => match ($operation) {
            'amount' => $receivable->update(['amount' => 1]),
            'quiet' => $receivable->forceFill(['paid_amount' => 118])->saveQuietly(),
            'delete' => $receivable->deleteQuietly(),
            'force-delete' => $receivable->forceDelete(),
            'relink' => $receivable->forceFill(['sale_id' => null])->save(),
            'bulk' => Receivable::query()->whereKey($receivable->id)->update(['amount' => 1]),
            'base' => Receivable::query()->whereKey($receivable->id)->toBase()->update(['AMOUNT' => 1]),
            default => $fixture['customer']->receivables()->whereKey($receivable->id)->delete(),
        };
        try {
            $write();
            $this->fail('Linked principal and origin must remain protected.');
        } catch (ValidationException) {
            $this->assertSame($before, $receivable->fresh()->getAttributes());
        }
    }

    public static function linkedWrites(): array
    {
        return array_map(fn ($case) => [$case], ['amount', 'quiet', 'delete', 'force-delete', 'relink', 'bulk', 'base', 'relation']);
    }

    public function test_text_reference_collision_does_not_adopt_a_manual_receivable(): void
    {
        $fixture = $this->context();
        $manual = Receivable::create([
            'reference' => 'Venta '.Sale::generateSaleNumber((string) $fixture['business']->id),
            'description' => 'Deuda manual distinta', 'amount' => 5, 'paid_amount' => 0,
            'due_date' => today()->addDays(7),
        ]);
        $sale = app(SaleService::class)->create($this->command($fixture));
        $this->assertSame('5.00', $manual->fresh()->amount);
        $this->assertNull($manual->fresh()->sale_id);
        $this->assertSame('118.00', app(SaleCreditService::class)->assertReceivable($sale)->original_amount);
    }

    public function test_request_keys_are_scoped_by_business_and_foreign_replay_is_rejected(): void
    {
        $first = $this->context();
        $command = $this->command($first);
        $sale = app(SaleService::class)->create($command);
        $second = $this->context();
        $other = $this->command($second);
        $other['idempotency_key'] = $command['idempotency_key'];
        $otherSale = app(SaleService::class)->create($other);
        $this->assertNotSame((string) $sale->id, (string) $otherSale->id);
        $this->assertSame(2, DB::table('sale_checkout_requests')->where('request_key', $command['idempotency_key'])->count());
        try {
            app(SaleService::class)->create($command);
            $this->fail('A foreign business request must not be replayed.');
        } catch (HttpExceptionInterface $exception) {
            $this->assertSame(404, $exception->getStatusCode());
            $this->assertSame(2, DB::table('receivables')->count());
        }
    }

    #[DataProvider('unauthorizedActors')]
    public function test_an_unauthorized_actor_cannot_confirm_credit_using_an_explicit_business_context(string $case): void
    {
        $fixture = $this->context();
        $command = $this->command($fixture);
        $before = $this->state($fixture);
        if ($case === 'non-member') {
            $this->actingAs(User::factory()->create());
        } elseif ($case === 'inactive-membership') {
            $fixture['user']->businesses()->updateExistingPivot($fixture['business']->id, ['is_active' => false]);
        } elseif ($case === 'viewer') {
            $fixture['user']->businesses()->updateExistingPivot($fixture['business']->id, ['role' => 'viewer']);
        } else {
            $command['user_id'] = User::factory()->create()->id;
        }
        try {
            app(SaleService::class)->create($command);
            $this->fail('An explicit context does not grant business membership.');
        } catch (HttpExceptionInterface $exception) {
            $this->assertSame(403, $exception->getStatusCode());
            $this->assertSame($before, $this->state($fixture));
        }
    }

    public static function unauthorizedActors(): array
    {
        return [['non-member'], ['inactive-membership'], ['viewer'], ['foreign-actor']];
    }

    #[DataProvider('filamentOutcomes')]
    public function test_filament_confirmation_is_atomic_and_keeps_the_request_key_after_rejection(bool $reject): void
    {
        $fixture = $this->context();
        $this->withoutVite();
        filament()->setCurrentPanel(filament()->getPanel('admin'));
        filament()->setTenant($fixture['business']);
        $page = Livewire::test(CreateSale::class)->fillForm([
            'customer_id' => $fixture['customer']->id, 'payment_method' => 'credito',
            'items' => [['product_id' => $fixture['product']->id, 'quantity' => 1, 'unit_price' => 100]],
            'subtotal' => 100, 'discount' => 0, 'tax_rate' => 18, 'tax' => 18, 'total' => 118, 'amount_paid' => 0,
        ]);
        $key = $page->get('checkoutKey');
        $before = $this->state($fixture);
        if ($reject) {
            Receivable::creating(fn () => false);
        }
        $page->call('create')->assertHasNoFormErrors();
        if ($reject) {
            $this->assertSame($before, $this->state($fixture));
            $this->assertSame($key, $page->get('checkoutKey'));

            return;
        }
        $this->assertNotSame($key, $page->get('checkoutKey'));
        $receipt = Receivable::withoutBusinessScope()->sole();
        $this->assertSame('118.00', $receipt->original_amount);
        Livewire::test(EditReceivable::class, ['record' => $receipt->id])
            ->assertActionHidden('delete')->fillForm(['notes' => 'Nota administrativa'])
            ->call('save')->assertHasNoFormErrors();
        $this->assertSame('Nota administrativa', $receipt->fresh()->notes);
        $this->assertSame('118.00', $receipt->fresh()->original_amount);
    }

    public static function filamentOutcomes(): array
    {
        return [[false], [true]];
    }

    public function test_base_insert_and_json_path_updates_cannot_bypass_linked_write_protection(): void
    {
        $fixture = $this->context();
        $sale = app(SaleService::class)->create($this->command($fixture));
        $receipt = app(SaleCreditService::class)->assertReceivable($sale);
        $before = $this->state($fixture);
        foreach ([
            fn () => Receivable::query()->whereKey($receipt->id)->toBase()->update(['amount->$' => 0]),
            fn () => Receivable::query()->toBase()->insert([
                'id' => (string) Str::uuid(), 'business_id' => $sale->business_id,
                'SALE_ID' => $sale->id, 'ORIGINAL_AMOUNT' => 118,
                'description' => 'Intento de origen', 'amount' => 118, 'due_date' => today()->toDateString(),
            ]),
        ] as $write) {
            $this->rejectsWithoutEffects($fixture, $before, $write);
        }
    }

    public function test_refund_and_replay_preserve_the_adjusted_principal_and_payments(): void
    {
        $fixture = $this->context();
        $command = $this->command($fixture);
        $sale = app(SaleService::class)->create($command);
        $receivable = app(SaleCreditService::class)->assertReceivable($sale);
        $receivable->registerPayment(20, 'transferencia');
        app(SaleCreditService::class)->refund($sale, '18.00');
        app(SaleService::class)->create($command);
        $this->assertSame('118.00', $receivable->fresh()->original_amount);
        $this->assertSame('100.00', $receivable->fresh()->amount);
        $this->assertSame('20.00', $receivable->fresh()->paid_amount);
        $this->assertSame('80.00', $receivable->fresh()->balance);
        app(SaleService::class)->cancel($sale->fresh());
        $this->assertSame((string) $receivable->id, (string) app(SaleCreditService::class)->assertReceivable($sale)->id);
    }

    public function test_accounting_rejects_a_versioned_source_with_a_missing_receivable_without_repairing_it(): void
    {
        $fixture = $this->context();
        AccountingService::createDefaultAccounts($fixture['business']);
        $sale = app(SaleService::class)->create($this->command($fixture));
        DB::table('receivables')->where('sale_id', $sale->id)->delete();
        try {
            AccountingService::createFromSale($sale);
            $this->fail('Accounting must not manufacture a missing receivable.');
        } catch (ValidationException) {
            $this->assertSame(0, DB::table('journal_entries')->count());
            $this->assertSame(0, DB::table('receivables')->where('sale_id', $sale->id)->count());
            $this->assertNotSame('processed', AccountingOutbox::withoutBusinessScope()->where('sale_id', $sale->id)->sole()->status);
        }
    }

    public function test_full_authorized_refund_does_not_delete_the_source_or_restore_debt_on_replay(): void
    {
        $fixture = $this->context();
        $command = $this->command($fixture);
        $sale = app(SaleService::class)->create($command);
        $receipt = app(SaleCreditService::class)->refund($sale, '118.00');
        app(SaleService::class)->create($command);
        $this->assertSame('118.00', $receipt->fresh()->original_amount);
        $this->assertSame('0.00', $receipt->fresh()->amount);
        $this->assertSame('0.00', $receipt->fresh()->balance);
        $this->assertSame('pagada', $receipt->fresh()->status);
        $this->assertFalse($receipt->fresh()->trashed());
    }

    public function test_business_global_receivable_collection_does_not_require_sales_branch_visibility(): void
    {
        $fixture = $this->context();
        $first = Branch::create(['name' => 'Sucursal A', 'code' => 'F2A', 'is_active' => true]);
        $second = Branch::create(['name' => 'Sucursal B', 'code' => 'F2B', 'is_active' => true]);
        session(['current_branch_id' => $first->id]);
        $sale = app(SaleService::class)->create($this->command($fixture));
        $this->assertSame((string) $first->id, (string) $sale->branch_id);
        $receipt = app(SaleCreditService::class)->assertReceivable($sale);
        $fixture['business']->forceFill(['features' => ['strict_branch_scope' => true]])->saveQuietly();
        $accountant = User::factory()->create();
        $accountant->businesses()->attach($fixture['business']->id, [
            'role' => 'accountant', 'is_active' => true, 'branch_id' => $second->id,
        ]);
        $this->actingAs($accountant);
        session(['current_branch_id' => $second->id]);
        $this->assertFalse(Sale::withoutBusinessScope()->whereKey($sale->id)->exists());
        $receipt->registerPayment(20, 'transferencia');
        $this->assertSame('98.00', $receipt->fresh()->balance);
        $this->assertSame('20.00', $receipt->fresh()->paid_amount);
        $this->assertFalse(Sale::withoutBusinessScope()->whereKey($sale->id)->exists());
    }

    private function rejectsWithoutEffects(array $fixture, array $before, callable $write): void
    {
        try {
            $write();
            $this->fail('The write must be rejected.');
        } catch (ValidationException) {
            $this->assertSame($before, $this->state($fixture));
        }
    }

    private function context(): array
    {
        $user = User::factory()->create();
        $business = Business::withoutEvents(fn () => Business::create([
            'name' => 'Crédito F2',
            'slug' => 'f2-credit-'.Str::uuid(),
            'type' => BusinessType::Colmado,
            'owner_id' => $user->id,
            'is_active' => true,
        ]));
        $user->businesses()->attach($business->id, ['role' => 'owner', 'is_active' => true]);
        $this->actingAs($user);
        app()->instance('currentBusinessId', $business->id);
        app()->instance('currentBusiness', $business);
        session(['current_business_id' => $business->id]);
        $customer = Customer::create(['name' => 'Cliente F2', 'is_active' => true]);
        $product = Product::forceCreate([
            'business_id' => $business->id, 'name' => 'Producto F2',
            'price' => 100, 'cost' => 30, 'stock' => 20, 'min_stock' => 0,
            'is_active' => true, 'track_stock' => true, 'inventory_behavior' => 'deduct_on_sale',
        ]);

        return compact('user', 'business', 'customer', 'product');
    }

    private function command(array $fixture): array
    {
        return [
            'idempotency_key' => (string) Str::uuid(), 'business_id' => $fixture['business']->id,
            'user_id' => $fixture['user']->id, 'customer_id' => $fixture['customer']->id,
            'payment_method' => 'credito', 'tax_rate' => 18,
            'items' => [['product_id' => $fixture['product']->id, 'quantity' => 1, 'unit_price' => 100]],
        ];
    }

    private function state(array $fixture): array
    {
        $state = ['stock' => (string) $fixture['product']->fresh()->stock];
        foreach (['sales', 'sale_items', 'receivables', 'receivable_payments', 'cash_sessions', 'inventory_movements', 'sales_daily',
            'accounting_outbox', 'sale_checkout_requests', 'journal_entries', 'journal_entry_lines'] as $table) {
            $state[$table] = DB::table($table)->orderBy('id')->get()->map(fn ($row) => (array) $row)->all();
        }

        return $state;
    }
}
