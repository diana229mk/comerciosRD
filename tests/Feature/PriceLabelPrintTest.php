<?php

namespace Tests\Feature;

use App\Enums\BusinessType;
use App\Models\Business;
use App\Models\Product;
use App\Models\User;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Tests\TestCase;

class PriceLabelPrintTest extends TestCase
{
    use DatabaseTransactions;

    public function test_price_label_print_fits_product_on_small_sticker(): void
    {
        $owner = User::factory()->create();
        $business = Business::create([
            'name' => 'Ferretería Etiquetas',
            'type' => BusinessType::Ferreteria,
            'owner_id' => $owner->id,
            'is_active' => true,
        ]);
        $owner->businesses()->attach($business->id, ['role' => 'owner', 'is_active' => true]);
        $this->actingAs($owner);
        app()->instance('currentBusinessId', $business->id);
        app()->instance('currentBusiness', $business);

        $product = Product::withoutBusinessScope()->forceCreate([
            'business_id' => $business->id,
            'name' => 'toma corriente',
            'sku' => 'jshe32',
            'barcode' => 'jshe32',
            'price' => 120,
            'stock' => 1,
            'is_active' => true,
            'track_stock' => true,
        ]);

        $response = $this->get(route('print.price-labels', [
            'ids' => $product->id,
            'copies' => 1,
            'size' => '40x25',
            'show_price' => 1,
        ]));

        $response->assertOk();
        $response->assertSee('toma corriente');
        $response->assertSee('jshe32');
        $response->assertSee('RD$ 120.00');
        $response->assertSee('width: 40mm');
        $response->assertSee('height: 25mm');
        $response->assertSee('overflow: hidden');
        $response->assertDontSee('Válida hasta');
    }
}
