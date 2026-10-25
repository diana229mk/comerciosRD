<?php

namespace Tests\Feature;

use App\Enums\BusinessType;
use App\Filament\Pages\Dashboard;
use App\Filament\Pages\Pos;
use App\Filament\Resources\CustomerResource;
use App\Filament\Resources\ProductCategoryResource;
use App\Filament\Resources\QuoteResource;
use App\Filament\Resources\SaleResource;
use App\Filament\Resources\StockCountResource;
use App\Models\Business;
use App\Models\User;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Tests\TestCase;

class SecondaryUserNavTest extends TestCase
{
    use DatabaseTransactions;

    protected function bind(User $user, Business $business): void
    {
        $this->actingAs($user);
        app()->instance('currentBusinessId', $business->id);
        app()->instance('currentBusiness', $business);
        session(['current_business_id' => $business->id]);
        filament()->setCurrentPanel(filament()->getPanel('admin'));
        filament()->setTenant($business);
    }

    public function test_seller_does_not_see_pos_dashboard_counts_or_categories(): void
    {
        $owner = User::factory()->create();
        $seller = User::factory()->create();
        $business = Business::create([
            'name' => 'Repuestos Nav',
            'type' => BusinessType::Repuestos,
            'owner_id' => $owner->id,
            'is_active' => true,
        ]);
        $seller->businesses()->attach($business->id, ['role' => 'seller', 'is_active' => true]);
        $this->bind($seller, $business);

        $this->assertFalse(Dashboard::shouldRegisterNavigation());
        $this->assertFalse(\App\Support\DashboardAccess::allowed());
        $this->assertFalse(Pos::shouldRegisterNavigation());
        $this->assertFalse(SaleResource::canViewAny());
        $this->assertFalse(StockCountResource::canViewAny());
        $this->assertFalse(ProductCategoryResource::canViewAny());
        $this->assertTrue(QuoteResource::canViewAny());
        $this->assertTrue(QuoteResource::canCreate());
        $this->assertTrue(CustomerResource::canViewAny());
        $this->assertTrue(CustomerResource::canCreate());
    }

    public function test_owner_and_admin_see_inicio_in_every_giro(): void
    {
        foreach (BusinessType::cases() as $type) {
            $owner = User::factory()->create();
            $admin = User::factory()->create();
            $seller = User::factory()->create();
            $business = Business::create([
                'name' => 'Giro '.$type->value,
                'type' => $type,
                'owner_id' => $owner->id,
                'is_active' => true,
            ]);
            $admin->businesses()->attach($business->id, ['role' => 'admin', 'is_active' => true]);
            $seller->businesses()->attach($business->id, ['role' => 'seller', 'is_active' => true]);

            $this->bind($owner, $business);
            $this->assertTrue(Dashboard::shouldRegisterNavigation(), "Dueño debe ver Inicio en {$type->value}");
            $this->assertTrue(\App\Support\DashboardAccess::allowed(), "Dueño dashboard {$type->value}");

            $this->bind($admin, $business);
            $this->assertTrue(Dashboard::shouldRegisterNavigation(), "Admin secundario debe ver Inicio en {$type->value}");
            $this->assertTrue(\App\Support\DashboardAccess::allowed(), "Admin dashboard {$type->value}");

            $this->bind($seller, $business);
            $this->assertFalse(Dashboard::shouldRegisterNavigation(), "Preventa no ve Inicio en {$type->value}");
        }
    }

    public function test_secondary_admin_sees_dashboard(): void
    {
        $owner = User::factory()->create();
        $admin = User::factory()->create();
        $business = Business::create([
            'name' => 'Admin Nav',
            'type' => BusinessType::Repuestos,
            'owner_id' => $owner->id,
            'is_active' => true,
        ]);
        $admin->businesses()->attach($business->id, ['role' => 'admin', 'is_active' => true]);
        $this->bind($admin, $business);

        $this->assertTrue(\App\Support\DashboardAccess::allowed());
        $this->assertTrue(Dashboard::shouldRegisterNavigation());
    }

    public function test_cashier_sees_pos_not_stock_counts(): void
    {
        $owner = User::factory()->create();
        $cashier = User::factory()->create();
        $business = Business::create([
            'name' => 'Caja Nav',
            'type' => BusinessType::Repuestos,
            'owner_id' => $owner->id,
            'is_active' => true,
        ]);
        $cashier->businesses()->attach($business->id, ['role' => 'cashier', 'is_active' => true]);
        $this->bind($cashier, $business);

        $this->assertTrue(Pos::shouldRegisterNavigation());
        $this->assertTrue(SaleResource::canViewAny());
        $this->assertTrue(QuoteResource::canViewAny());
        $this->assertTrue(QuoteResource::canCreate());
        $this->assertTrue(QuoteResource::shouldRegisterNavigation());
        $this->assertFalse(StockCountResource::canViewAny());
        $this->assertFalse(Dashboard::shouldRegisterNavigation());
        $this->assertFalse(ProductCategoryResource::canViewAny());
    }
}
