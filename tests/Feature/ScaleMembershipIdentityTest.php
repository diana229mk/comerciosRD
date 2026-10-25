<?php

namespace Tests\Feature;

use App\Contracts\ProductSearch;
use App\Contracts\RecordsSale;
use App\Enums\BusinessType;
use App\Models\Business;
use App\Models\Product;
use App\Models\User;
use App\Services\MembershipIdentity;
use App\Services\ProductSearchService;
use App\Services\SaleService;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Tests\TestCase;

class ScaleMembershipIdentityTest extends TestCase
{
    use DatabaseTransactions;

    public function test_email_login_without_slug_still_works(): void
    {
        $user = User::factory()->create(['email' => 'cajero@test.rd']);
        $business = Business::create([
            'name' => 'Colmado Norte',
            'type' => BusinessType::Colmado,
            'owner_id' => $user->id,
            'is_active' => true,
        ]);
        $user->businesses()->attach($business->id, ['role' => 'cashier', 'is_active' => true]);

        $resolved = app(MembershipIdentity::class)->resolveUser('cajero@test.rd');

        $this->assertNotNull($resolved);
        $this->assertSame($user->id, $resolved->id);
    }

    public function test_slug_mismatch_does_not_resolve_user(): void
    {
        $user = User::factory()->create(['email' => 'cajero2@test.rd']);
        $business = Business::create([
            'name' => 'Colmado Sur',
            'type' => BusinessType::Colmado,
            'owner_id' => $user->id,
            'is_active' => true,
        ]);
        $user->businesses()->attach($business->id, ['role' => 'cashier', 'is_active' => true]);

        $resolved = app(MembershipIdentity::class)->resolveUser('cajero2@test.rd', 'no-existe');

        $this->assertNull($resolved);
    }

    public function test_login_name_resolves_inside_business(): void
    {
        $user = User::factory()->create(['email' => 'dueño-caja@test.rd']);
        $business = Business::create([
            'name' => 'Farmacia Centro',
            'type' => BusinessType::Farmacia,
            'owner_id' => $user->id,
            'is_active' => true,
        ]);
        $user->businesses()->attach($business->id, [
            'role' => 'cashier',
            'is_active' => true,
            'login_name' => 'caja1',
        ]);

        $resolved = app(MembershipIdentity::class)->resolveUser('caja1', $business->slug);

        $this->assertNotNull($resolved);
        $this->assertSame($user->id, $resolved->id);
    }

    public function test_search_and_sale_ports_are_bound(): void
    {
        $this->assertInstanceOf(ProductSearchService::class, app(ProductSearch::class));
        $this->assertInstanceOf(SaleService::class, app(RecordsSale::class));
    }

    public function test_product_search_does_not_leak_other_tenant(): void
    {
        $user = User::factory()->create();
        $businessA = Business::create([
            'name' => 'A Search',
            'type' => BusinessType::Colmado,
            'owner_id' => $user->id,
            'is_active' => true,
        ]);
        $businessB = Business::create([
            'name' => 'B Search',
            'type' => BusinessType::Colmado,
            'owner_id' => $user->id,
            'is_active' => true,
        ]);
        $user->businesses()->attach($businessA->id, ['role' => 'owner', 'is_active' => true]);

        Product::withoutBusinessScope()->forceCreate([
            'business_id' => $businessA->id,
            'name' => 'Leche A',
            'sku' => 'LECHE-A',
            'barcode' => '770000000001',
            'price' => 10,
            'cost' => 5,
            'stock' => 5,
            'is_active' => true,
        ]);
        Product::withoutBusinessScope()->forceCreate([
            'business_id' => $businessB->id,
            'name' => 'Leche B',
            'sku' => 'LECHE-B',
            'barcode' => '770000000002',
            'price' => 10,
            'cost' => 5,
            'stock' => 5,
            'is_active' => true,
        ]);

        $this->actingAs($user);
        app()->instance('currentBusinessId', $businessA->id);
        app()->instance('currentBusiness', $businessA);

        $hits = app(ProductSearch::class)->suggest('Leche', BusinessType::Colmado, 8);
        $names = $hits->pluck('name')->all();

        $this->assertContains('Leche A', $names);
        $this->assertNotContains('Leche B', $names);

        $exact = app(ProductSearch::class)->findExact('770000000002', BusinessType::Colmado);
        $this->assertNull($exact);
    }
}
