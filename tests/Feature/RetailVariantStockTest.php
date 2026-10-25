<?php

namespace Tests\Feature;

use App\Enums\BusinessType;
use App\Models\Business;
use App\Models\InventoryMovement;
use App\Models\Product;
use App\Models\ProductVariant;
use App\Models\User;
use App\Services\SaleService;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Tests\TestCase;

class RetailVariantStockTest extends TestCase
{
    use DatabaseTransactions;

    public function test_retail_variant_stock_is_deducted_and_tracked(): void
    {
        $user = User::factory()->create();
        $business = Business::create([
            'name' => 'Boutique Demo',
            'type' => BusinessType::Tienda,
            'owner_id' => $user->id,
            'is_active' => true,
        ]);
        $user->businesses()->attach($business->id, ['role' => 'owner', 'is_active' => true]);

        $product = Product::withoutBusinessScope()->forceCreate([
            'business_id' => $business->id,
            'name' => 'Camisa Oxford',
            'price' => 150,
            'cost' => 80,
            'stock' => 99,
            'min_stock' => 1,
            'track_stock' => true,
            'inventory_behavior' => 'deduct_on_sale',
        ]);

        $variant = ProductVariant::withoutBusinessScope()->forceCreate([
            'business_id' => $business->id,
            'product_id' => $product->id,
            'sku' => 'CAM-M-AZ',
            'barcode' => 'CAM-M-AZ',
            'attributes' => ['size' => 'M', 'color' => 'Azul'],
            'price' => 150,
            'cost' => 80,
            'stock' => 5,
            'is_active' => true,
        ]);

        $sale = app(SaleService::class)->create([
            'business_id' => $business->id,
            'user_id' => $user->id,
            'payment_method' => 'efectivo',
            'amount_paid' => 400,
            'items' => [[
                'product_id' => $product->id,
                'variant_id' => $variant->id,
                'quantity' => 2,
                'unit_price' => 150,
            ]],
        ]);

        $variant->refresh();

        $this->assertSame(3.0, (float) $variant->stock);
        $this->assertTrue(
            InventoryMovement::withoutBusinessScope()
                ->where('business_id', $business->id)
                ->where('variant_id', $variant->id)
                ->where('reference_id', $sale->id)
                ->where('type', InventoryMovement::TYPE_SALE)
                ->exists()
        );
    }
}
