<?php

namespace Tests\Feature;

use App\Enums\BusinessType;
use App\Filament\Resources\ProductCategoryResource\Pages\CreateProductCategory;
use App\Models\Business;
use App\Models\ProductCategory;
use App\Models\User;
use App\Services\AuthContextResolver;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Livewire\Livewire;
use Tests\TestCase;

class ProductCategoryTenantTest extends TestCase
{
    use DatabaseTransactions;

    public function test_owner_can_open_product_category_create_page_for_tenant(): void
    {
        $owner = User::factory()->create();
        $business = Business::create([
            'name' => 'Ferretería Categorías',
            'type' => BusinessType::Ferreteria,
            'owner_id' => $owner->id,
            'is_active' => true,
            'enabled_modules' => ['products', 'categories'],
        ]);
        $owner->businesses()->attach($business->id, [
            'role' => 'owner',
            'is_active' => true,
        ]);

        $this->withoutVite();
        $this->actingAs($owner);
        app(AuthContextResolver::class)->apply($owner, $business);
        app()->instance('currentBusinessId', $business->id);
        app()->instance('currentBusiness', $business);
        filament()->setCurrentPanel(filament()->getPanel('admin'));
        filament()->setTenant($business);

        $this->get('/admin/'.$business->slug.'/product-categories/create')
            ->assertOk()
            ->assertSee('Nombre de la Categoría');

        Livewire::test(CreateProductCategory::class)
            ->fillForm([
                'name' => 'Herramientas',
                'slug' => 'herramientas',
                'is_active' => true,
            ])
            ->call('create')
            ->assertHasNoFormErrors();

        $this->assertDatabaseHas('product_categories', [
            'business_id' => $business->id,
            'slug' => 'herramientas',
        ]);
    }
}
