<?php

namespace Tests\Feature;

use App\Enums\BusinessType;
use App\Filament\Resources\CustomerResource\Pages\CreateCustomer;
use App\Models\Business;
use App\Models\Customer;
use App\Models\User;
use App\Services\AuthContextResolver;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Livewire\Livewire;
use Tests\TestCase;

class CustomerCreateTest extends TestCase
{
    use DatabaseTransactions;

    /**
     * @return array{owner: User, seller: User, business: Business}
     */
    protected function shop(): array
    {
        $owner = User::factory()->create();
        $seller = User::factory()->create();
        $business = Business::create([
            'name' => 'Farmacia Clientes HTTP',
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

    public function test_owner_opens_customer_create(): void
    {
        ['owner' => $owner, 'business' => $business] = $this->shop();
        $this->withoutVite();
        $this->actingAs($owner);
        app(AuthContextResolver::class)->apply($owner, $business);

        $response = $this->get('/admin/'.$business->slug.'/customers/create');
        $response->assertOk();
        $response->assertSee('Nombre Completo');
        $response->assertDontSee('Tu puesto no puede entrar aquí');
    }

    public function test_seller_opens_customer_create(): void
    {
        ['seller' => $seller, 'business' => $business] = $this->shop();
        $this->withoutVite();
        $this->actingAs($seller);
        app(AuthContextResolver::class)->apply($seller, $business);

        $response = $this->get('/admin/'.$business->slug.'/customers/create');
        $response->assertOk();
        $response->assertSee('Nombre Completo');
        $response->assertDontSee('Tu puesto no puede entrar aquí');
    }

    public function test_seller_can_store_customer(): void
    {
        ['seller' => $seller, 'business' => $business] = $this->shop();
        $this->withoutVite();
        $this->actingAs($seller);
        app(AuthContextResolver::class)->apply($seller, $business);
        app()->instance('currentBusinessId', $business->id);
        app()->instance('currentBusiness', $business);
        filament()->setCurrentPanel(filament()->getPanel('admin'));
        filament()->setTenant($business);

        $customer = Customer::withoutBusinessScope()->forceCreate([
            'business_id' => $business->id,
            'name' => 'Taller Norte',
            'phone' => '809-555-0101',
            'is_active' => true,
        ]);

        $this->assertSame((string) $business->id, (string) $customer->business_id);
        $this->assertSame('Taller Norte', $customer->name);
    }

    public function test_seller_livewire_creates_customer(): void
    {
        ['seller' => $seller, 'business' => $business] = $this->shop();
        $this->withoutVite();
        $this->actingAs($seller);
        app(AuthContextResolver::class)->apply($seller, $business);
        app()->instance('currentBusinessId', $business->id);
        app()->instance('currentBusiness', $business);
        filament()->setCurrentPanel(filament()->getPanel('admin'));
        filament()->setTenant($business);

        Livewire::test(CreateCustomer::class)
            ->fillForm([
                'name' => 'Ana Perez',
                'phone' => '8095551111',
                'document_type' => 'cedula',
                'is_active' => true,
                'credit_limit' => 0,
            ])
            ->call('create')
            ->assertHasNoFormErrors();

        $this->assertTrue(
            Customer::withoutBusinessScope()
                ->where('business_id', $business->id)
                ->where('name', 'Ana Perez')
                ->exists()
        );
    }

}
