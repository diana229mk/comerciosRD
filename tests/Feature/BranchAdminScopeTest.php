<?php

namespace Tests\Feature;

use App\Enums\BusinessType;
use App\Filament\Resources\BranchResource;
use App\Models\Branch;
use App\Models\Business;
use App\Models\Sale;
use App\Models\User;
use App\Services\AuthContextResolver;
use App\Services\BranchAccessContext;
use Illuminate\Foundation\Testing\DatabaseTransactions;
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
}
