<?php

namespace Tests\Feature;

use App\Enums\BusinessType;
use App\Models\Business;
use App\Models\User;
use App\Services\AuthContextResolver;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Tests\TestCase;

/**
 * Alta de comercio + destino por puesto. No toca POS/facturas.
 */
class BusinessOnboardingTeamTest extends TestCase
{
    use DatabaseTransactions;

    public function test_nuevo_negocio_queda_con_sucursal_principal(): void
    {
        $owner = User::factory()->create(['email' => 'dueno-onboard@test.rd']);

        $business = Business::create([
            'name' => 'Colmado Prueba Onboard',
            'type' => BusinessType::Colmado,
            'owner_id' => $owner->id,
            'is_active' => true,
        ]);

        $branch = $business->ensureMainBranch();

        $this->assertSame('Principal', $branch->name);
        $this->assertTrue($branch->is_main);
        $this->assertTrue($branch->is_active);
        $this->assertSame((string) $business->id, (string) $branch->business_id);
        $this->assertTrue($business->mainBranch()?->is($branch));

        $again = $business->ensureMainBranch();
        $this->assertSame((string) $branch->id, (string) $again->id);
    }

    public function test_cajero_queda_con_destino_pos_despues_de_apply(): void
    {
        $owner = User::factory()->create(['email' => 'dueno-dest@test.rd']);
        $cashier = User::factory()->create(['email' => 'cajero-dest@test.rd']);

        $business = Business::create([
            'name' => 'Ferretería Destino',
            'type' => BusinessType::Ferreteria,
            'owner_id' => $owner->id,
            'is_active' => true,
            'slug' => 'ferreteria-destino-'.substr($owner->id, 0, 8),
        ]);

        $owner->businesses()->attach($business->id, ['role' => 'owner', 'is_active' => true]);
        $cashier->businesses()->attach($business->id, ['role' => 'cashier', 'is_active' => true]);

        $this->actingAs($cashier);

        $result = app(AuthContextResolver::class)->apply($cashier, $business);

        $this->assertSame(AuthContextResolver::STATUS_OK, $result['status']);
        $this->assertSame('cashier', $result['role']);
        $this->assertSame((string) $business->id, (string) session(AuthContextResolver::SESSION_BUSINESS_ID));
        $this->assertIsString($result['panel_url']);
        $this->assertTrue(
            str_contains((string) $result['panel_url'], '/caja/')
            || str_contains((string) $result['panel_url'], '/pos')
            || str_contains((string) $result['panel_url'], 'pos'),
            'El cajero debe ir a la caja, no al dashboard. URL: '.$result['panel_url']
        );
    }

    public function test_inventario_y_vendedor_no_caen_al_login(): void
    {
        $owner = User::factory()->create(['email' => 'dueno-roles@test.rd']);
        $inventory = User::factory()->create(['email' => 'inv-roles@test.rd']);
        $seller = User::factory()->create(['email' => 'vend-roles@test.rd']);

        $business = Business::create([
            'name' => 'Tienda Roles',
            'type' => BusinessType::Tienda,
            'owner_id' => $owner->id,
            'is_active' => true,
            'slug' => 'tienda-roles-'.substr($owner->id, 0, 8),
        ]);

        $inventory->businesses()->attach($business->id, ['role' => 'inventory', 'is_active' => true]);
        $seller->businesses()->attach($business->id, ['role' => 'seller', 'is_active' => true]);

        $this->actingAs($inventory);
        $inv = app(AuthContextResolver::class)->apply($inventory, $business);
        $this->assertSame(AuthContextResolver::STATUS_OK, $inv['status']);
        $this->assertNotEmpty($inv['panel_url']);

        $this->actingAs($seller);
        app()->instance('currentBusinessId', $business->id);
        filament()->setCurrentPanel(filament()->getPanel('admin'));
        filament()->setTenant($business);
        $sel = app(AuthContextResolver::class)->apply($seller, $business);
        $this->assertSame(AuthContextResolver::STATUS_OK, $sel['status']);
        $this->assertNotEmpty($sel['panel_url']);
        $this->assertTrue($seller->canPerform('create_orders'));
        $this->assertFalse($seller->canPerform('create_sales'));
        $this->assertTrue(
            str_contains((string) $sel['panel_url'], 'preventa')
            || str_contains((string) $sel['panel_url'], 'order')
            || str_contains((string) $sel['panel_url'], 'quote')
            || str_contains((string) $sel['panel_url'], 'orden'),
            'El preventa debe ir a su pantalla. URL: '.$sel['panel_url']
        );
    }
}
