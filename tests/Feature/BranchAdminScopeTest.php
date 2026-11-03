<?php

namespace Tests\Feature;

use App\Enums\BusinessType;
use App\Filament\Resources\BranchResource;
use App\Models\Branch;
use App\Models\Business;
use App\Models\InventoryMovement;
use App\Models\Product;
use App\Models\ProductBatch;
use App\Models\Sale;
use App\Models\StockCount;
use App\Models\StockCountItem;
use App\Models\User;
use App\Services\AuthContextResolver;
use App\Services\BranchAccessContext;
use App\Services\InventoryService;
use App\Services\StockCountService;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\DataProvider;
use RuntimeException;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Tests\TestCase;

class BranchAdminScopeTest extends TestCase
{
    use DatabaseTransactions;

    /**
     * @return array{owner: User, business: Business, main: Branch, other: Branch}
     */
    protected function seedBranches(): array
    {
        $owner = User::factory()->create();
        $business = Business::create([
            'name' => 'Multi Local',
            'type' => BusinessType::Cafeteria,
            'owner_id' => $owner->id,
            'is_active' => true,
            'features' => [
                'strict_branch_scope' => true,
                'strict_branch_stock' => true,
            ],
        ]);
        $owner->businesses()->attach($business->id, ['role' => 'owner', 'is_active' => true]);

        $main = Branch::withoutBusinessScope()->forceCreate([
            'business_id' => $business->id,
            'name' => 'Principal',
            'is_main' => true,
            'is_active' => true,
        ]);
        $other = Branch::withoutBusinessScope()->forceCreate([
            'business_id' => $business->id,
            'name' => 'Villa Mella',
            'is_main' => false,
            'is_active' => true,
        ]);

        return compact('owner', 'business', 'main', 'other');
    }

    public function test_tenant_cannot_create_branches(): void
    {
        ['owner' => $owner, 'business' => $business] = $this->seedBranches();
        $this->actingAs($owner);
        app()->instance('currentBusinessId', $business->id);
        filament()->setCurrentPanel(filament()->getPanel('admin'));
        filament()->setTenant($business);

        $this->assertFalse(BranchResource::canCreate());
        $this->assertFalse(BranchResource::canDelete(null));
    }

    public function test_branch_admin_is_locked_to_their_local(): void
    {
        ['business' => $business, 'other' => $other] = $this->seedBranches();
        $admin = User::factory()->create();
        $admin->businesses()->attach($business->id, [
            'role' => 'admin',
            'is_active' => true,
            'branch_id' => $other->id,
        ]);

        $this->actingAs($admin);
        session([
            AuthContextResolver::SESSION_BUSINESS_ID => (string) $business->id,
            AuthContextResolver::SESSION_BRANCH_ID => (string) $other->id,
        ]);
        app()->instance('currentBusinessId', $business->id);
        app(BranchAccessContext::class)->forget();

        $snap = app(BranchAccessContext::class)->resolveFor($admin, (string) $business->id);

        $this->assertFalse($snap->canSwitchBranches);
        $this->assertTrue($snap->shouldRestrictReads());
        $this->assertSame((string) $other->id, $snap->effectiveBranchId());
    }

    public function test_owner_all_branches_sees_unfiltered_sales(): void
    {
        ['owner' => $owner, 'business' => $business, 'main' => $main, 'other' => $other] = $this->seedBranches();
        $this->actingAs($owner);
        session([
            AuthContextResolver::SESSION_BUSINESS_ID => (string) $business->id,
        ]);
        session()->forget(AuthContextResolver::SESSION_BRANCH_ID);
        app()->instance('currentBusinessId', $business->id);
        app(BranchAccessContext::class)->forget();

        Sale::withoutBusinessScope()->forceCreate([
            'business_id' => $business->id,
            'branch_id' => $main->id,
            'status' => 'completada',
            'subtotal' => 100,
            'tax' => 18,
            'total' => 118,
            'sold_at' => now(),
            'sale_number' => 'VTA-ALL-MAIN',
            'number' => 'VTA-ALL-MAIN',
        ]);
        Sale::withoutBusinessScope()->forceCreate([
            'business_id' => $business->id,
            'branch_id' => $other->id,
            'status' => 'completada',
            'subtotal' => 50,
            'tax' => 9,
            'total' => 59,
            'sold_at' => now(),
            'sale_number' => 'VTA-ALL-OTHER',
            'number' => 'VTA-ALL-OTHER',
        ]);

        $snap = app(BranchAccessContext::class)->resolveFor($owner, (string) $business->id);
        $this->assertTrue($snap->canSwitchBranches);
        $this->assertFalse($snap->shouldRestrictReads());
        $this->assertSame(2, Sale::query()->count());
    }

    public function test_owner_local_view_filters_sales(): void
    {
        ['owner' => $owner, 'business' => $business, 'main' => $main, 'other' => $other] = $this->seedBranches();
        $this->actingAs($owner);
        session([
            AuthContextResolver::SESSION_BUSINESS_ID => (string) $business->id,
            AuthContextResolver::SESSION_BRANCH_ID => (string) $other->id,
        ]);
        app()->instance('currentBusinessId', $business->id);
        app(BranchAccessContext::class)->forget();

        Sale::withoutBusinessScope()->forceCreate([
            'business_id' => $business->id,
            'branch_id' => $main->id,
            'status' => 'completada',
            'subtotal' => 100,
            'tax' => 18,
            'total' => 118,
            'sold_at' => now(),
            'sale_number' => 'VTA-TEST-MAIN',
            'number' => 'VTA-TEST-MAIN',
        ]);
        Sale::withoutBusinessScope()->forceCreate([
            'business_id' => $business->id,
            'branch_id' => $other->id,
            'status' => 'completada',
            'subtotal' => 50,
            'tax' => 9,
            'total' => 59,
            'sold_at' => now(),
            'sale_number' => 'VTA-TEST-OTHER',
            'number' => 'VTA-TEST-OTHER',
        ]);

        $this->assertSame(1, Sale::query()->count());
        $this->assertSame((string) $other->id, (string) Sale::query()->first()->branch_id);
    }

    public function test_stock_counts_are_branch_scoped_and_cannot_be_updated_across_branches(): void
    {
        ['business' => $business, 'main' => $main, 'other' => $other] = $this->seedBranches();
        $cashier = User::factory()->create();
        $cashier->businesses()->attach($business->id, [
            'role' => 'cashier',
            'is_active' => true,
            'branch_id' => $other->id,
        ]);
        $this->actingAs($cashier);
        session([
            AuthContextResolver::SESSION_BUSINESS_ID => (string) $business->id,
            AuthContextResolver::SESSION_BRANCH_ID => (string) $other->id,
        ]);
        app()->instance('currentBusinessId', $business->id);
        app(BranchAccessContext::class)->forget();

        $mainCount = StockCount::withoutBusinessScope()->forceCreate([
            'business_id' => $business->id,
            'branch_id' => $main->id,
            'code' => 'CC-MAIN-001',
            'status' => StockCount::STATUS_IN_PROGRESS,
            'scope' => StockCount::SCOPE_FULL,
        ]);
        $otherCount = StockCount::withoutBusinessScope()->forceCreate([
            'business_id' => $business->id,
            'branch_id' => $other->id,
            'code' => 'CC-OTHER-001',
            'status' => StockCount::STATUS_IN_PROGRESS,
            'scope' => StockCount::SCOPE_FULL,
        ]);
        $product = Product::withoutBusinessScope()->forceCreate([
            'business_id' => $business->id,
            'branch_id' => $main->id,
            'name' => 'Producto de otra sucursal',
            'sku' => 'SKU-BRANCH-COUNT-001',
            'price' => 20,
            'cost' => 10,
            'stock' => 5,
            'min_stock' => 0,
            'is_active' => true,
        ]);
        $foreignItem = StockCountItem::withoutBusinessScope()->forceCreate([
            'business_id' => $business->id,
            'branch_id' => $main->id,
            'stock_count_id' => $mainCount->id,
            'product_id' => $product->id,
            'expected_quantity' => 5,
            'unit_cost' => 10,
        ]);

        $this->assertSame([$otherCount->id], StockCount::query()->pluck('id')->all());

        $foreignCount = StockCount::withoutGlobalScopes()->findOrFail($mainCount->id);
        try {
            app(StockCountService::class)->saveCount($foreignCount, [$foreignItem->id => 0]);
            $this->fail('A branch user must not update another branch stock count.');
        } catch (NotFoundHttpException $exception) {
            $this->assertSame(404, $exception->getStatusCode());
        }

        $this->assertNull(
            StockCountItem::withoutGlobalScopes()->findOrFail($foreignItem->id)->counted_quantity,
        );
    }

    public function test_null_branch_stock_counts_and_items_are_hidden_from_branch_users(): void
    {
        ['business' => $business, 'main' => $main] = $this->seedBranches();
        $cashier = User::factory()->create();
        $cashier->businesses()->attach($business->id, [
            'role' => 'cashier',
            'is_active' => true,
            'branch_id' => $main->id,
        ]);
        $this->actingAs($cashier);
        session([
            AuthContextResolver::SESSION_BUSINESS_ID => (string) $business->id,
            AuthContextResolver::SESSION_BRANCH_ID => (string) $main->id,
        ]);
        app()->instance('currentBusinessId', $business->id);
        app(BranchAccessContext::class)->forget();

        $count = StockCount::withoutEvents(fn () => StockCount::withoutBusinessScope()->forceCreate([
            'business_id' => $business->id,
            'branch_id' => null,
            'code' => 'CC-NULL-001',
            'status' => StockCount::STATUS_IN_PROGRESS,
            'scope' => StockCount::SCOPE_FULL,
        ]));
        $product = Product::withoutBusinessScope()->forceCreate([
            'business_id' => $business->id,
            'name' => 'Producto compartido',
            'sku' => 'SKU-NULL-BRANCH',
            'price' => 20,
            'cost' => 10,
            'stock' => 5,
            'min_stock' => 0,
            'is_active' => true,
        ]);
        StockCountItem::withoutEvents(fn () => StockCountItem::withoutBusinessScope()->forceCreate([
            'business_id' => $business->id,
            'branch_id' => null,
            'stock_count_id' => $count->id,
            'product_id' => $product->id,
            'expected_quantity' => 5,
            'unit_cost' => 10,
        ]));

        $this->assertSame(0, StockCount::query()->count());
        $this->assertSame(0, StockCountItem::query()->count());
    }

    public function test_open_stock_count_only_snapshots_batches_for_the_authorized_branch(): void
    {
        ['business' => $business, 'main' => $main, 'other' => $other] = $this->seedBranches();
        $cashier = User::factory()->create();
        $cashier->businesses()->attach($business->id, [
            'role' => 'cashier',
            'is_active' => true,
            'branch_id' => $main->id,
        ]);
        $this->actingAs($cashier);
        session([
            AuthContextResolver::SESSION_BUSINESS_ID => (string) $business->id,
            AuthContextResolver::SESSION_BRANCH_ID => (string) $main->id,
        ]);
        app()->instance('currentBusinessId', $business->id);
        app(BranchAccessContext::class)->forget();

        $product = Product::withoutBusinessScope()->forceCreate([
            'business_id' => $business->id,
            'name' => 'Producto por lotes',
            'sku' => 'SKU-BRANCH-BATCH-001',
            'price' => 20,
            'cost' => 10,
            'stock' => 5,
            'min_stock' => 0,
            'is_active' => true,
        ]);
        $mainBatch = ProductBatch::withoutBusinessScope()->forceCreate([
            'business_id' => $business->id,
            'product_id' => $product->id,
            'branch_id' => $main->id,
            'code' => 'BATCH-MAIN',
            'quantity' => 3,
            'initial_quantity' => 3,
            'cost' => 10,
            'is_blocked' => false,
        ]);
        ProductBatch::withoutBusinessScope()->forceCreate([
            'business_id' => $business->id,
            'product_id' => $product->id,
            'branch_id' => $other->id,
            'code' => 'BATCH-OTHER',
            'quantity' => 2,
            'initial_quantity' => 2,
            'cost' => 10,
            'is_blocked' => false,
        ]);

        $count = app(StockCountService::class)->open((string) $business->id);

        $this->assertSame(
            [$mainBatch->id],
            $count->items()->pluck('batch_id')->all(),
        );
    }

    #[DataProvider('branchStockCountBalances')]
    public function test_stock_counts_only_adjust_stock_with_a_verifiable_branch_balance(
        bool $sharedProduct,
        ?string $batchBranch,
        bool $canCount,
    ): void {
        ['business' => $business, 'main' => $main, 'other' => $other] = $this->seedBranches();
        $cashier = User::factory()->create();
        $cashier->businesses()->attach($business->id, [
            'role' => 'cashier',
            'is_active' => true,
            'branch_id' => $main->id,
        ]);
        $this->actingAs($cashier);
        session([
            AuthContextResolver::SESSION_BUSINESS_ID => (string) $business->id,
            AuthContextResolver::SESSION_BRANCH_ID => (string) $main->id,
        ]);
        app()->instance('currentBusinessId', $business->id);
        app(BranchAccessContext::class)->forget();

        $product = Product::withoutEvents(fn () => Product::withoutBusinessScope()->forceCreate([
            'business_id' => $business->id,
            'branch_id' => $sharedProduct ? null : $main->id,
            'name' => 'Producto de conteo',
            'sku' => 'SKU-BRANCH-BALANCE',
            'price' => 20,
            'cost' => 10,
            'stock' => 5,
            'min_stock' => 0,
            'is_active' => true,
        ]));
        $batch = $batchBranch === null ? null : ProductBatch::withoutEvents(
            fn () => ProductBatch::withoutBusinessScope()->forceCreate([
                'business_id' => $business->id,
                'product_id' => $product->id,
                'branch_id' => match ($batchBranch) {
                    'other' => $other->id,
                    'local' => $main->id,
                    default => null,
                },
                'code' => 'BATCH-BRANCH-BALANCE',
                'quantity' => 5,
                'initial_quantity' => 5,
                'cost' => 10,
                'is_blocked' => false,
            ]),
        );

        $service = app(StockCountService::class);
        $count = $service->open((string) $business->id);
        $this->assertSame($canCount ? 1 : 0, $count->items()->count());
        $this->assertSame($canCount ? 1 : 0, (int) $count->total_items);

        if ($canCount) {
            $item = $count->items()->sole();
            $this->assertSame($batch?->id, $item->batch_id);
            $this->assertSame(5.0, (float) $item->expected_quantity);
            $count = $service->saveCount($count, [$item->id => 0]);
            $completed = $service->complete($count);

            $this->assertSame(StockCount::STATUS_COMPLETED, $completed->status);
            $this->assertSame(0.0, (float) $product->fresh()->stock);
            if ($batch) {
                $this->assertSame(0.0, (float) $batch->fresh()->quantity);
            }
            $movement = InventoryMovement::withoutGlobalScopes()
                ->where('reference_id', $count->id)
                ->sole();
            $this->assertSame((string) $main->id, (string) $movement->branch_id);
            $this->assertSame($batch?->id, $movement->batch_id);

            return;
        }

        // Simula un snapshot inseguro generado antes de esta corrección.
        $item = StockCountItem::withoutBusinessScope()->forceCreate([
            'business_id' => $business->id,
            'branch_id' => $main->id,
            'stock_count_id' => $count->id,
            'product_id' => $product->id,
            'batch_id' => null,
            'expected_quantity' => 5,
            'counted_quantity' => 0,
            'unit_cost' => 10,
        ]);
        try {
            $service->complete($count);
            $this->fail('An ambiguous legacy snapshot must not adjust aggregate stock.');
        } catch (RuntimeException $exception) {
            $this->assertStringContainsString('saldo verificable', $exception->getMessage());
        }

        $this->assertSame(5.0, (float) $product->fresh()->stock);
        if ($batch) {
            $this->assertSame(5.0, (float) $batch->fresh()->quantity);
        }
        $this->assertSame(StockCount::STATUS_IN_PROGRESS, $count->fresh()->status);
        $this->assertNull($item->fresh()->adjustment_movement_id);
        $this->assertSame(0, InventoryMovement::withoutGlobalScopes()->where('reference_id', $count->id)->count());
    }

    public static function branchStockCountBalances(): array
    {
        return [
            'shared product with only foreign batches' => [true, 'other', false],
            'local product with only foreign batches' => [false, 'other', false],
            'shared product without a local balance' => [true, null, false],
            'local product without batches' => [false, null, true],
            'shared product with a local batch' => [true, 'local', true],
            'shared product with an unassigned batch' => [true, 'common', false],
            'local product with an unassigned batch' => [false, 'common', false],
        ];
    }

    #[DataProvider('invalidStockCountContexts')]
    public function test_stock_count_operations_fail_closed_for_invalid_contexts(string $context): void
    {
        [
            'business' => $business, 'main' => $main, 'cashier' => $cashier,
            'owner' => $owner, 'product' => $product, 'mainBatch' => $mainBatch,
            'otherBatch' => $otherBatch, 'count' => $count,
        ] = $this->stockCountScenario();

        match ($context) {
            'guest' => auth()->logout(),
            'missing business' => session()->forget(AuthContextResolver::SESSION_BUSINESS_ID),
            'wrong business' => session([AuthContextResolver::SESSION_BUSINESS_ID => $owner->id]),
            'inactive user' => DB::table('users')->where('id', $cashier->id)->update(['is_active' => false]),
            'inactive business' => DB::table('businesses')->where('id', $business->id)->update(['is_active' => false]),
            'inactive branch' => DB::table('branches')->where('id', $main->id)->update(['is_active' => false]),
            'revoked membership' => DB::table('user_business')->where('business_id', $business->id)
                ->where('user_id', $cashier->id)->update(['is_active' => false]),
            'unassigned branch with feature disabled' => (function () use ($business, $cashier): void {
                $business->forceFill(['features' => []])->saveQuietly();
                DB::table('user_business')->where('business_id', $business->id)
                    ->where('user_id', $cashier->id)->update(['branch_id' => null]);
                session()->forget(AuthContextResolver::SESSION_BRANCH_ID);
            })(),
            'owner global view' => (function () use ($owner): void {
                $this->actingAs($owner);
                session()->forget(AuthContextResolver::SESSION_BRANCH_ID);
            })(),
        };

        $rejected = [];
        foreach (['open', 'saveCount', 'complete', 'cancel'] as $operation) {
            try {
                match ($operation) {
                    'open' => app(StockCountService::class)->open((string) $business->id),
                    'saveCount' => app(StockCountService::class)->saveCount($count, [$count->items->sole()->id => 0]),
                    'complete' => app(StockCountService::class)->complete($count),
                    'cancel' => app(StockCountService::class)->cancel($count),
                };
                $this->fail("Invalid context must reject {$operation}.");
            } catch (AuthorizationException|NotFoundHttpException) {
                $rejected[] = $operation;
            }
        }

        $this->assertSame(['open', 'saveCount', 'complete', 'cancel'], $rejected);
        $this->assertSame(1, DB::table('stock_counts')->where('business_id', $business->id)->count());
        $this->assertSame(StockCount::STATUS_IN_PROGRESS, $count->fresh()->status);
        $this->assertSame(12.0, (float) DB::table('stock_count_items')->where('stock_count_id', $count->id)->value('counted_quantity'));
        $this->assertSame(15.0, (float) $product->fresh()->stock);
        $this->assertSame(10.0, (float) $mainBatch->fresh()->quantity);
        $this->assertSame(5.0, (float) $otherBatch->fresh()->quantity);
        $this->assertSame(0, DB::table('inventory_movements')->where('reference_id', $count->id)->count());
    }

    public static function invalidStockCountContexts(): array
    {
        return array_map(fn (string $context): array => [$context], [
            'guest', 'missing business', 'wrong business', 'inactive user', 'inactive business',
            'inactive branch', 'revoked membership', 'unassigned branch with feature disabled', 'owner global view',
        ]);
    }

    #[DataProvider('foreignStockCountReferences')]
    public function test_stock_count_adjustments_reject_foreign_product_and_batch_references(string $reference): void
    {
        [
            'business' => $business, 'main' => $main, 'other' => $other, 'owner' => $owner,
            'product' => $product, 'mainBatch' => $mainBatch, 'otherBatch' => $otherBatch, 'count' => $count,
        ] = $this->stockCountScenario();

        if ($reference === 'product branch') {
            DB::table('products')->where('id', $product->id)->update(['branch_id' => $other->id]);
        } elseif (in_array($reference, ['batch branch', 'batch NULL'], true)) {
            DB::table('product_batches')->where('id', $mainBatch->id)
                ->update(['branch_id' => $reference === 'batch branch' ? $other->id : null]);
        } else {
            $foreignBusiness = $reference === 'batch product' ? $business : $this->foreignStockCountBusiness($owner);
            $foreignProduct = Product::withoutEvents(fn () => Product::withoutBusinessScope()->forceCreate([
                'business_id' => $foreignBusiness->id, 'branch_id' => null,
                'name' => 'Producto ajeno al item', 'sku' => 'SKU-FOREIGN-COUNT',
                'price' => 20, 'cost' => 10, 'stock' => 7, 'min_stock' => 0, 'is_active' => true,
            ]));
            $foreignBatch = ProductBatch::withoutEvents(fn () => ProductBatch::withoutBusinessScope()->forceCreate([
                'business_id' => $foreignBusiness->id, 'product_id' => $foreignProduct->id,
                'branch_id' => $main->id, 'code' => 'BATCH-FOREIGN-COUNT',
                'quantity' => 7, 'initial_quantity' => 7, 'cost' => 10, 'is_blocked' => false,
            ]));
            DB::table('stock_count_items')->where('stock_count_id', $count->id)->update(
                $reference === 'product business'
                    ? ['product_id' => $foreignProduct->id]
                    : ['batch_id' => $foreignBatch->id],
            );
        }

        try {
            app(StockCountService::class)->complete($count);
            $this->fail('A foreign reference must never adjust stock.');
        } catch (RuntimeException $exception) {
            $this->assertStringContainsString('no pertenece', $exception->getMessage());
        }

        $this->assertSame(StockCount::STATUS_IN_PROGRESS, $count->fresh()->status);
        $this->assertSame(15.0, (float) $product->fresh()->stock);
        $this->assertSame(10.0, (float) $mainBatch->fresh()->quantity);
        $this->assertSame(5.0, (float) $otherBatch->fresh()->quantity);
        $this->assertSame(0, DB::table('inventory_movements')->where('reference_id', $count->id)->count());
        if (isset($foreignProduct, $foreignBatch)) {
            $this->assertSame(7.0, (float) $foreignProduct->fresh()->stock);
            $this->assertSame(7.0, (float) $foreignBatch->fresh()->quantity);
        }
    }

    public static function foreignStockCountReferences(): array
    {
        return array_map(fn (string $reference): array => [$reference], [
            'product branch', 'product business', 'batch branch', 'batch NULL', 'batch business', 'batch product',
        ]);
    }

    #[DataProvider('foreignStockCountOwners')]
    public function test_forged_count_attributes_cannot_bypass_persisted_business_and_branch(string $scope): void
    {
        ['business' => $business, 'main' => $main, 'other' => $other, 'owner' => $owner] = $this->stockCountScenario();
        $foreignBusiness = $scope === 'business' ? $this->foreignStockCountBusiness($owner) : $business;
        $foreignCount = StockCount::withoutEvents(fn () => StockCount::withoutBusinessScope()->forceCreate([
            'business_id' => $foreignBusiness->id, 'branch_id' => $other->id,
            'code' => 'CC-FOREIGN-IDENTITY', 'status' => StockCount::STATUS_IN_PROGRESS,
            'scope' => StockCount::SCOPE_FULL,
        ]));
        $foreignCount->business_id = $business->id;
        $foreignCount->branch_id = $main->id;

        foreach (['saveCount', 'complete', 'cancel'] as $operation) {
            try {
                $operation === 'saveCount'
                    ? app(StockCountService::class)->saveCount($foreignCount, [])
                    : app(StockCountService::class)->{$operation}($foreignCount);
                $this->fail('Forged attributes must not bypass persisted count ownership.');
            } catch (NotFoundHttpException) {
                $persisted = StockCount::withoutGlobalScopes()->findOrFail($foreignCount->id);
                $this->assertSame((string) $foreignBusiness->id, (string) $persisted->business_id);
                $this->assertSame((string) $other->id, (string) $persisted->branch_id);
                $this->assertSame(StockCount::STATUS_IN_PROGRESS, $persisted->status);
            }
        }
    }

    public static function foreignStockCountOwners(): array
    {
        return [['branch'], ['business']];
    }

    private function stockCountScenario(): array
    {
        ['business' => $business, 'main' => $main, 'other' => $other, 'owner' => $owner] = $this->seedBranches();
        $cashier = User::factory()->create();
        $cashier->businesses()->attach($business->id, [
            'role' => 'cashier', 'is_active' => true, 'branch_id' => $main->id,
        ]);
        $this->actingAs($cashier);
        session([
            AuthContextResolver::SESSION_BUSINESS_ID => (string) $business->id,
            AuthContextResolver::SESSION_BRANCH_ID => (string) $main->id,
        ]);
        app()->instance('currentBusinessId', $business->id);
        app(BranchAccessContext::class)->forget();

        $product = Product::withoutEvents(fn () => Product::withoutBusinessScope()->forceCreate([
            'business_id' => $business->id, 'branch_id' => null,
            'name' => 'Catálogo compartido con saldos locales', 'sku' => 'SKU-COUNT-SAFE',
            'price' => 20, 'cost' => 10, 'stock' => 15, 'min_stock' => 0, 'is_active' => true,
        ]));
        $mainBatch = ProductBatch::withoutBusinessScope()->forceCreate([
            'business_id' => $business->id, 'product_id' => $product->id, 'branch_id' => $main->id,
            'code' => 'COUNT-MAIN', 'quantity' => 10, 'initial_quantity' => 10, 'cost' => 10, 'is_blocked' => false,
        ]);
        $otherBatch = ProductBatch::withoutBusinessScope()->forceCreate([
            'business_id' => $business->id, 'product_id' => $product->id, 'branch_id' => $other->id,
            'code' => 'COUNT-OTHER', 'quantity' => 5, 'initial_quantity' => 5, 'cost' => 10, 'is_blocked' => false,
        ]);
        $count = app(StockCountService::class)->open((string) $business->id);
        $count = app(StockCountService::class)->saveCount($count, [$count->items->sole()->id => 12]);

        return compact('business', 'main', 'other', 'owner', 'cashier', 'product', 'mainBatch', 'otherBatch', 'count');
    }

    private function foreignStockCountBusiness(User $owner): Business
    {
        return Business::withoutEvents(fn () => Business::create([
            'name' => 'Empresa ajena al conteo', 'slug' => 'foreign-count-'.bin2hex(random_bytes(8)),
            'type' => BusinessType::Cafeteria, 'owner_id' => $owner->id, 'is_active' => true,
        ]));
    }

    public function test_strict_branch_stock_refuses_fefo_without_verified_business_context(): void
    {
        ['business' => $business, 'main' => $main] = $this->seedBranches();
        $cashier = User::factory()->create();
        $cashier->businesses()->attach($business->id, [
            'role' => 'cashier',
            'is_active' => true,
            'branch_id' => $main->id,
        ]);
        $this->actingAs($cashier);
        session()->forget([
            AuthContextResolver::SESSION_BUSINESS_ID,
            AuthContextResolver::SESSION_BRANCH_ID,
        ]);
        app()->forgetInstance('currentBusinessId');

        $product = Product::withoutBusinessScope()->forceCreate([
            'business_id' => $business->id,
            'name' => 'Producto con lote',
            'sku' => 'SKU-STRICT-STOCK-001',
            'price' => 20,
            'cost' => 10,
            'stock' => 5,
            'min_stock' => 0,
            'is_active' => true,
        ]);
        $batch = ProductBatch::withoutBusinessScope()->forceCreate([
            'business_id' => $business->id,
            'product_id' => $product->id,
            'branch_id' => $main->id,
            'code' => 'BATCH-STRICT',
            'quantity' => 5,
            'initial_quantity' => 5,
            'cost' => 10,
            'is_blocked' => false,
        ]);

        try {
            app(InventoryService::class)->consumeFromBatchesFefo($product, 1);
            $this->fail('Strict branch stock must reject FEFO without verified tenant context.');
        } catch (AuthorizationException $exception) {
            $this->assertSame('No se pudo verificar el contexto de sucursal.', $exception->getMessage());
        }

        $this->assertSame(5.0, (float) $batch->fresh()->quantity);
    }

    public function test_branch_context_denies_users_without_access_to_requested_business(): void
    {
        ['business' => $business] = $this->seedBranches();
        $member = User::factory()->create();
        $member->businesses()->attach($business->id, [
            'role' => 'cashier',
            'is_active' => true,
        ]);
        $unrelatedOwner = User::factory()->create();
        $unrelatedBusiness = Business::create([
            'name' => 'Negocio no autorizado',
            'type' => BusinessType::Cafeteria,
            'owner_id' => $unrelatedOwner->id,
            'is_active' => true,
        ]);
        $this->actingAs($member);
        app(BranchAccessContext::class)->forget();

        $this->expectException(AuthorizationException::class);
        app(BranchAccessContext::class)->resolveFor($member, (string) $unrelatedBusiness->id);
    }
}
