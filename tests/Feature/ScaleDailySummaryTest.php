<?php

namespace Tests\Feature;

use App\Contracts\RecordsSale;
use App\Enums\BusinessType;
use App\Jobs\IssueElectronicInvoiceJob;
use App\Jobs\PostSaleAccountingJob;
use App\Models\Business;
use App\Models\Product;
use App\Models\SalesDaily;
use App\Models\User;
use App\Support\TenantContext;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\Bus;
use Tests\TestCase;

class ScaleDailySummaryTest extends TestCase
{
    use DatabaseTransactions;

    protected function seedSaleContext(): array
    {
        $user = User::factory()->create();
        $business = Business::create([
            'name' => 'Colmado Escala',
            'type' => BusinessType::Colmado,
            'owner_id' => $user->id,
            'is_active' => true,
        ]);
        $user->businesses()->attach($business->id, ['role' => 'owner', 'is_active' => true]);

        $this->actingAs($user);
        app()->instance('currentBusinessId', $business->id);
        app()->instance('currentBusiness', $business);
        session(['current_business_id' => $business->id]);

        $product = Product::withoutBusinessScope()->forceCreate([
            'business_id' => $business->id,
            'name' => 'Arroz 1lb',
            'price' => 50,
            'cost' => 30,
            'stock' => 20,
            'min_stock' => 1,
            'track_stock' => true,
            'inventory_behavior' => 'deduct_on_sale',
        ]);

        return compact('user', 'business', 'product');
    }

    public function test_completed_sale_increments_daily_summary(): void
    {
        ['user' => $user, 'business' => $business, 'product' => $product] = $this->seedSaleContext();

        $sale = app(RecordsSale::class)->create([
            'business_id' => $business->id,
            'user_id' => $user->id,
            'payment_method' => 'efectivo',
            'amount_paid' => 59,
            'items' => [[
                'product_id' => $product->id,
                'quantity' => 1,
                'unit_price' => 50,
            ]],
        ]);

        $row = SalesDaily::withoutBusinessScope()
            ->where('business_id', $business->id)
            ->whereDate('report_date', now()->toDateString())
            ->first();

        $this->assertNotNull($row);
        $this->assertSame(1, (int) $row->tickets);
        $this->assertEqualsWithDelta((float) $sale->total, (float) $row->total, 0.01);
        $this->assertEqualsWithDelta(30.0, (float) $row->cogs, 0.01);
    }

    public function test_cancelled_sale_decrements_daily_summary(): void
    {
        ['user' => $user, 'business' => $business, 'product' => $product] = $this->seedSaleContext();

        $sale = app(RecordsSale::class)->create([
            'business_id' => $business->id,
            'user_id' => $user->id,
            'payment_method' => 'efectivo',
            'amount_paid' => 59,
            'items' => [[
                'product_id' => $product->id,
                'quantity' => 1,
                'unit_price' => 50,
            ]],
        ]);

        app(\App\Services\SaleService::class)->cancel($sale);

        $row = SalesDaily::withoutBusinessScope()
            ->where('business_id', $business->id)
            ->whereDate('report_date', now()->toDateString())
            ->first();

        $this->assertNotNull($row);
        $this->assertSame(0, (int) $row->tickets);
        $this->assertEqualsWithDelta(0.0, (float) $row->total, 0.01);
    }

    public function test_summary_is_isolated_per_business(): void
    {
        ['user' => $userA, 'business' => $businessA, 'product' => $productA] = $this->seedSaleContext();

        $userB = User::factory()->create();
        $businessB = Business::create([
            'name' => 'Otro Local',
            'type' => BusinessType::Colmado,
            'owner_id' => $userB->id,
            'is_active' => true,
        ]);
        $userB->businesses()->attach($businessB->id, ['role' => 'owner', 'is_active' => true]);

        app(RecordsSale::class)->create([
            'business_id' => $businessA->id,
            'user_id' => $userA->id,
            'payment_method' => 'efectivo',
            'amount_paid' => 59,
            'items' => [[
                'product_id' => $productA->id,
                'quantity' => 1,
                'unit_price' => 50,
            ]],
        ]);

        $this->assertSame(
            1,
            SalesDaily::withoutBusinessScope()->where('business_id', $businessA->id)->count()
        );
        $this->assertSame(
            0,
            SalesDaily::withoutBusinessScope()->where('business_id', $businessB->id)->count()
        );
    }

    public function test_post_sale_jobs_are_dispatched_when_queue_enabled(): void
    {
        config([
            'scale.post_sale_queue' => true,
            'ecf.enabled' => true,
            'ecf.validation_approved' => true,
        ]);
        Bus::fake([PostSaleAccountingJob::class, IssueElectronicInvoiceJob::class]);

        ['user' => $user, 'business' => $business, 'product' => $product] = $this->seedSaleContext();

        $sale = app(RecordsSale::class)->create([
            'business_id' => $business->id,
            'user_id' => $user->id,
            'payment_method' => 'efectivo',
            'amount_paid' => 59,
            'items' => [[
                'product_id' => $product->id,
                'quantity' => 1,
                'unit_price' => 50,
            ]],
        ]);

        Bus::assertDispatched(PostSaleAccountingJob::class, function (PostSaleAccountingJob $job) use ($sale): bool {
            return $job->saleId === (string) $sale->id
                && $job->businessId === (string) $sale->business_id;
        });
        Bus::assertDispatched(IssueElectronicInvoiceJob::class);
    }

    public function test_tenant_context_restores_previous_binding(): void
    {
        $a = Business::create([
            'name' => 'A',
            'type' => BusinessType::Colmado,
            'is_active' => true,
        ]);
        $b = Business::create([
            'name' => 'B',
            'type' => BusinessType::Colmado,
            'is_active' => true,
        ]);

        app()->instance('currentBusinessId', (string) $a->id);

        $seen = TenantContext::run((string) $b->id, function () {
            return app('currentBusinessId');
        });

        $this->assertSame((string) $b->id, $seen);
        $this->assertSame((string) $a->id, app('currentBusinessId'));
    }
}
