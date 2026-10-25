<?php

namespace Tests\Feature;

use App\Enums\BusinessType;
use App\Models\Business;
use App\Models\Product;
use App\Models\Sale;
use App\Models\User;
use App\Support\Itbis;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Tests\TestCase;

class ItbisIncludedTest extends TestCase
{
    use DatabaseTransactions;

    public function test_default_still_adds_itbis_on_top(): void
    {
        $parts = Itbis::split(100, 18, false);
        $this->assertEquals(100.0, $parts['net']);
        $this->assertEquals(18.0, $parts['tax']);
        $this->assertEquals(118.0, $parts['gross']);
    }

    public function test_included_price_does_not_charge_twice(): void
    {
        $parts = Itbis::split(118, 18, true);
        $this->assertEquals(118.0, $parts['gross']);
        $this->assertEqualsWithDelta(100.0, $parts['net'], 0.01);
        $this->assertEqualsWithDelta(18.0, $parts['tax'], 0.01);
    }

    public function test_pos_checkout_with_included_tax_charges_shelf_price(): void
    {
        $owner = User::factory()->create();
        $business = Business::create([
            'name' => 'Colmado ITBIS',
            'type' => BusinessType::Colmado,
            'owner_id' => $owner->id,
            'is_active' => true,
            'settings' => ['tax' => ['rate' => 18, 'prices_include_tax' => true]],
        ]);
        $owner->businesses()->attach($business->id, ['role' => 'owner', 'is_active' => true]);
        $this->actingAs($owner);
        app()->instance('currentBusinessId', $business->id);
        app()->instance('currentBusiness', $business);

        $product = Product::withoutBusinessScope()->forceCreate([
            'business_id' => $business->id,
            'name' => 'Aceite',
            'sku' => 'ACE-1',
            'barcode' => '746000999001',
            'price' => 118,
            'stock' => 10,
            'is_active' => true,
            'track_stock' => true,
            'inventory_behavior' => 'deduct_on_sale',
        ]);

        $this->postJson('/caja/'.$business->slug.'/api/cash/open', [
            'opening_amount' => 200,
        ])->assertOk();

        $response = $this->postJson('/caja/'.$business->slug.'/api/checkout', [
            'items' => [[
                'product_id' => $product->id,
                'qty' => 1,
                'price' => 118,
            ]],
            'payment_method' => 'efectivo',
            'amount_tendered' => 118,
            'tax_rate' => 18,
        ]);

        $response->assertOk()->assertJsonPath('ok', true);
        $sale = Sale::withoutBusinessScope()->whereKey($response->json('sale_id'))->first();
        $this->assertNotNull($sale);
        $this->assertEqualsWithDelta(118.0, (float) $sale->total, 0.05);
        $this->assertEqualsWithDelta(18.0, (float) $sale->tax, 0.05);
    }
}
