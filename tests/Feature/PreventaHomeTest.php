<?php

namespace Tests\Feature;

use App\Enums\BusinessType;
use App\Filament\Pages\PreventaHome;
use App\Filament\Resources\QuoteResource;
use App\Models\Business;
use App\Models\User;
use App\Services\AuthContextResolver;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Tests\TestCase;

class PreventaHomeTest extends TestCase
{
    use DatabaseTransactions;

    /**
     * @return array{owner: User, seller: User, business: Business}
     */
    protected function pharmacySeller(): array
    {
        $owner = User::factory()->create();
        $seller = User::factory()->create();
        $business = Business::create([
            'name' => 'Farmacia Preventa HTTP',
            'type' => BusinessType::Farmacia,
            'owner_id' => $owner->id,
            'is_active' => true,
            'enabled_modules' => [
                'products', 'categories', 'inventory_movements', 'sales', 'customers',
                'receivables', 'expenses', 'cash_register', 'reports', 'employees',
                'prescriptions', 'stock_counts',
            ],
        ]);
        $owner->businesses()->attach($business->id, ['role' => 'owner', 'is_active' => true]);
        $seller->businesses()->attach($business->id, ['role' => 'seller', 'is_active' => true]);

        return compact('owner', 'seller', 'business');
    }

    public function test_seller_destination_is_preventa_even_without_quotes_module_flag(): void
    {
        ['seller' => $seller, 'business' => $business] = $this->pharmacySeller();

        $this->actingAs($seller);
        $this->withoutVite();
        app()->instance('currentBusinessId', $business->id);
        app()->instance('currentBusiness', $business);
        filament()->setCurrentPanel(filament()->getPanel('admin'));
        filament()->setTenant($business);

        $result = app(AuthContextResolver::class)->apply($seller, $business);

        $this->assertSame('seller', $result['role']);
        $this->assertTrue(str_contains((string) $result['panel_url'], '/preventa'), $result['panel_url']);
        $this->assertTrue($business->hasModule('quotes'));
        $this->assertTrue($business->hasModule('orders'));
        $this->assertTrue(QuoteResource::canViewAny());
        $this->assertTrue(PreventaHome::canAccess());
    }

    public function test_seller_http_opens_preventa_not_lock_screen(): void
    {
        ['seller' => $seller, 'business' => $business] = $this->pharmacySeller();

        $this->withoutVite();
        $this->actingAs($seller);
        app(AuthContextResolver::class)->apply($seller, $business);

        $slug = $business->slug;
        $dashboard = $this->get('/admin/'.$slug);
        $dashboard->assertRedirect('/admin/'.$slug.'/preventa');

        $preventa = $this->get('/admin/'.$slug.'/preventa');
        $preventa->assertOk();
        $preventa->assertDontSee('Tu puesto no puede entrar aquí');
        $preventa->assertSee('Preventa');
        $preventa->assertSee('Cotizaciones');

        $quotes = $this->get('/admin/'.$slug.'/quotes');
        $quotes->assertOk();
        $quotes->assertDontSee('Tu puesto no puede entrar aquí');
    }

    public function test_seller_forbidden_page_redirects_to_preventa_instead_of_dead_button(): void
    {
        ['seller' => $seller, 'business' => $business] = $this->pharmacySeller();

        $this->withoutVite();
        $this->actingAs($seller);
        app(AuthContextResolver::class)->apply($seller, $business);

        $slug = $business->slug;
        $blocked = $this->get('/admin/'.$slug.'/stock-counts');
        $blocked->assertRedirect('/admin/'.$slug.'/preventa');
    }
}
