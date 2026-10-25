<?php

namespace Tests\Feature;

use App\Enums\BusinessType;
use App\Models\Business;
use App\Models\Product;
use App\Models\ProductCategory;
use App\Models\ProductKitItem;
use App\Models\User;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Tests\TestCase;

class QrMenuTest extends TestCase
{
    use DatabaseTransactions;

    /**
     * @return array{user: User, business: Business, burger: Product, pan: Product}
     */
    protected function seedCafe(): array
    {
        $user = User::factory()->create();
        $business = Business::create([
            'name' => 'Café QR',
            'type' => BusinessType::Cafeteria,
            'owner_id' => $user->id,
            'is_active' => true,
        ]);
        $user->businesses()->attach($business->id, ['role' => 'owner', 'is_active' => true]);

        $cat = ProductCategory::withoutBusinessScope()->forceCreate([
            'business_id' => $business->id,
            'name' => 'Platos',
            'slug' => 'platos',
            'is_active' => true,
            'sort_order' => 1,
        ]);

        $pan = Product::withoutBusinessScope()->forceCreate([
            'business_id' => $business->id,
            'name' => 'Pan de receta',
            'price' => 10,
            'cost' => 4,
            'stock' => 40,
            'is_active' => true,
            'track_stock' => true,
            'is_kit' => false,
        ]);
        $burger = Product::withoutBusinessScope()->forceCreate([
            'business_id' => $business->id,
            'category_id' => $cat->id,
            'name' => 'Hamburguesa de la casa',
            'description' => 'Con queso',
            'price' => 250,
            'cost' => 80,
            'stock' => 0,
            'is_active' => true,
            'track_stock' => false,
            'is_kit' => true,
        ]);
        ProductKitItem::withoutBusinessScope()->forceCreate([
            'business_id' => $business->id,
            'kit_product_id' => $burger->id,
            'component_product_id' => $pan->id,
            'quantity' => 1,
        ]);

        return compact('user', 'business', 'burger', 'pan');
    }

    public function test_public_menu_shows_plates_not_ingredients_or_cost(): void
    {
        ['business' => $business] = $this->seedCafe();

        $this->get('/m/'.$business->slug)
            ->assertOk()
            ->assertSee('Café QR', false)
            ->assertSee('Hamburguesa de la casa', false)
            ->assertSee('250', false)
            ->assertDontSee('Pan de receta', false)
            ->assertDontSee('80.00', false)
            ->assertDontSee('cost', false);
    }

    public function test_public_menu_does_not_leak_other_business(): void
    {
        ['business' => $a] = $this->seedCafe();
        $user = User::factory()->create();
        $b = Business::create([
            'name' => 'Otro Café',
            'type' => BusinessType::Cafeteria,
            'owner_id' => $user->id,
            'is_active' => true,
        ]);
        Product::withoutBusinessScope()->forceCreate([
            'business_id' => $b->id,
            'name' => 'Secreto del otro',
            'price' => 99,
            'is_active' => true,
        ]);

        $this->get('/m/'.$a->slug)
            ->assertOk()
            ->assertDontSee('Secreto del otro', false);
    }

    public function test_inactive_business_menu_is_404(): void
    {
        ['business' => $business] = $this->seedCafe();
        $business->forceFill(['is_active' => false])->save();

        $this->get('/m/'.$business->slug)->assertNotFound();
    }

    public function test_qr_svg_is_served(): void
    {
        ['business' => $business] = $this->seedCafe();

        $this->get('/m/'.$business->slug.'/qr.svg')
            ->assertOk()
            ->assertHeader('Content-Type', 'image/svg+xml; charset=UTF-8')
            ->assertSee('<svg', false);
    }

    public function test_poster_page_renders(): void
    {
        ['business' => $business] = $this->seedCafe();

        $this->get('/m/'.$business->slug.'/cartel')
            ->assertOk()
            ->assertSee('Escanea para ver el menú', false)
            ->assertSee('<svg', false);
    }
}
