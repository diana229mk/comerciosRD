<?php

namespace Tests\Feature;

use App\Enums\BusinessType;
use App\Enums\PurchaseStatus;
use App\Models\Business;
use App\Models\Product;
use App\Models\Purchase;
use App\Models\User;
use App\Services\ReorderService;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Tests\TestCase;

class ReorderRestockTest extends TestCase
{
    use DatabaseTransactions;

    public function test_restock_now_puts_sold_out_product_back_on_shelf(): void
    {
        $owner = User::factory()->create();
        $business = Business::create([
            'name' => 'Ferretería Reposición',
            'type' => BusinessType::Ferreteria,
            'owner_id' => $owner->id,
            'is_active' => true,
            'enabled_modules' => Business::defaultModulesFor(BusinessType::Ferreteria),
        ]);
        $owner->businesses()->attach($business->id, ['role' => 'owner', 'is_active' => true]);

        $this->actingAs($owner);
        app()->instance('currentBusinessId', $business->id);
        app()->instance('currentBusiness', $business);
        filament()->setCurrentPanel(filament()->getPanel('admin'));
        filament()->setTenant($business);

        $product = Product::withoutBusinessScope()->forceCreate([
            'business_id' => $business->id,
            'name' => 'toma corriente',
            'sku' => 'jshe32',
            'price' => 100,
            'cost' => 65,
            'stock' => 0,
            'min_stock' => 1,
            'track_stock' => true,
            'is_active' => true,
            'inventory_behavior' => 'deduct_on_sale',
        ]);

        $orders = app(ReorderService::class)->restockNow((string) $business->id, [
            [
                'product_id' => (string) $product->id,
                'quantity' => 1,
                'unit_cost' => 65,
            ],
        ], (string) $owner->id);

        $this->assertCount(1, $orders);
        $this->assertEquals(PurchaseStatus::Recibida, $orders[0]->status);

        $fresh = Product::withoutBusinessScope()->find($product->id);
        $this->assertEquals(1.0, (float) $fresh->stock);

        $this->assertTrue(
            Purchase::withoutBusinessScope()
                ->where('business_id', $business->id)
                ->where('status', PurchaseStatus::Recibida)
                ->exists()
        );
    }
}
