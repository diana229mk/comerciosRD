<?php

namespace Tests\Feature;

use App\Enums\BusinessType;
use App\Filament\Pages\CafeteriaDashboard;
use App\Filament\Pages\HardwareDashboard;
use App\Filament\Pages\PharmacyDashboard;
use App\Filament\Pages\QrMenu;
use App\Filament\Pages\RetailVariantMatrix;
use App\Filament\Resources\GiftCardResource;
use App\Filament\Resources\PrescriptionResource;
use App\Filament\Resources\SpecialOrderResource;
use App\Models\Business;
use App\Models\User;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Tests\TestCase;

class GiroNavigationTest extends TestCase
{
    use DatabaseTransactions;

    /**
     * @return array{user: User, business: Business}
     */
    protected function bindGiro(BusinessType $type, array $extraModules = []): array
    {
        $user = User::factory()->create();
        $business = Business::create([
            'name' => 'Giro '.$type->value,
            'type' => $type,
            'owner_id' => $user->id,
            'is_active' => true,
            'enabled_modules' => array_values(array_unique(array_merge(
                Business::defaultModulesFor($type),
                $extraModules
            ))),
        ]);
        $user->businesses()->attach($business->id, ['role' => 'owner', 'is_active' => true]);
        $this->actingAs($user);
        app()->instance('currentBusinessId', $business->id);
        app()->instance('currentBusiness', $business);
        filament()->setCurrentPanel(filament()->getPanel('admin'));
        filament()->setTenant($business);

        return compact('user', 'business');
    }

    public function test_cafeteria_does_not_see_other_giro_modules_even_if_plan_has_them(): void
    {
        $this->bindGiro(BusinessType::Cafeteria, [
            'special_orders', 'gift_cards', 'seasons', 'prescriptions', 'serials',
        ]);

        $this->assertFalse(SpecialOrderResource::shouldRegisterNavigation());
        $this->assertFalse(GiftCardResource::shouldRegisterNavigation());
        $this->assertFalse(PrescriptionResource::shouldRegisterNavigation());
        $this->assertFalse(HardwareDashboard::shouldRegisterNavigation());
        $this->assertFalse(PharmacyDashboard::shouldRegisterNavigation());
        $this->assertFalse(RetailVariantMatrix::shouldRegisterNavigation());
        $this->assertTrue(CafeteriaDashboard::shouldRegisterNavigation());
        $this->assertTrue(QrMenu::shouldRegisterNavigation());
    }

    public function test_farmacia_does_not_see_ferreteria_tienda_or_cocina(): void
    {
        $this->bindGiro(BusinessType::Farmacia, [
            'special_orders', 'gift_cards', 'kits',
        ]);

        $this->assertTrue(PrescriptionResource::shouldRegisterNavigation());
        $this->assertTrue(PharmacyDashboard::shouldRegisterNavigation());
        $this->assertFalse(SpecialOrderResource::shouldRegisterNavigation());
        $this->assertFalse(GiftCardResource::shouldRegisterNavigation());
        $this->assertFalse(CafeteriaDashboard::shouldRegisterNavigation());
        $this->assertFalse(QrMenu::shouldRegisterNavigation());
        $this->assertFalse(HardwareDashboard::shouldRegisterNavigation());
    }

    public function test_ferreteria_keeps_hardware_hides_pharmacy_retail_cocina(): void
    {
        $this->bindGiro(BusinessType::Ferreteria);

        $this->assertTrue(SpecialOrderResource::shouldRegisterNavigation());
        $this->assertTrue(HardwareDashboard::shouldRegisterNavigation());
        $this->assertFalse(PrescriptionResource::shouldRegisterNavigation());
        $this->assertFalse(GiftCardResource::shouldRegisterNavigation());
        $this->assertFalse(CafeteriaDashboard::shouldRegisterNavigation());
        $this->assertFalse(QrMenu::shouldRegisterNavigation());
        $this->assertFalse(PharmacyDashboard::shouldRegisterNavigation());
        $this->assertFalse(RetailVariantMatrix::shouldRegisterNavigation());
    }

    public function test_tienda_sees_gift_cards_not_farmacia_or_pedidos_especiales(): void
    {
        $this->bindGiro(BusinessType::Tienda);

        $this->assertTrue(GiftCardResource::shouldRegisterNavigation());
        $this->assertTrue(RetailVariantMatrix::shouldRegisterNavigation());
        $this->assertFalse(SpecialOrderResource::shouldRegisterNavigation());
        $this->assertFalse(PrescriptionResource::shouldRegisterNavigation());
        $this->assertFalse(HardwareDashboard::shouldRegisterNavigation());
        $this->assertFalse(CafeteriaDashboard::shouldRegisterNavigation());
        $this->assertFalse(QrMenu::shouldRegisterNavigation());
    }

    public function test_colmado_has_no_giro_special_groups(): void
    {
        $this->bindGiro(BusinessType::Colmado, [
            'special_orders', 'gift_cards', 'prescriptions',
        ]);

        $this->assertFalse(SpecialOrderResource::shouldRegisterNavigation());
        $this->assertFalse(GiftCardResource::shouldRegisterNavigation());
        $this->assertFalse(PrescriptionResource::shouldRegisterNavigation());
        $this->assertFalse(HardwareDashboard::shouldRegisterNavigation());
        $this->assertFalse(PharmacyDashboard::shouldRegisterNavigation());
        $this->assertFalse(CafeteriaDashboard::shouldRegisterNavigation());
        $this->assertFalse(QrMenu::shouldRegisterNavigation());
        $this->assertFalse(RetailVariantMatrix::shouldRegisterNavigation());
    }
}
