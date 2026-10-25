<?php

namespace Tests\Feature;

use App\Enums\BusinessType;
use App\Models\Business;
use App\Models\User;
use App\Services\AuthContextResolver;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Tests\TestCase;

class RepuestosPosTest extends TestCase
{
    use DatabaseTransactions;

    public function test_owner_opens_repuestos_pos_without_error(): void
    {
        $owner = User::factory()->create();
        $business = Business::create([
            'name' => 'Respuesto GG Test',
            'type' => BusinessType::Repuestos,
            'owner_id' => $owner->id,
            'is_active' => true,
        ]);
        $owner->businesses()->attach($business->id, ['role' => 'owner', 'is_active' => true]);

        $this->withoutVite();
        $this->actingAs($owner);
        app(AuthContextResolver::class)->apply($owner, $business);

        $home = $this->get('/admin/'.$business->slug);
        $home->assertOk();
        $home->assertDontSee('Tu puesto no puede entrar aquí');

        $pos = $this->get('/admin/'.$business->slug.'/pos');
        $pos->assertOk();
        $pos->assertSee('Escanear / Buscar producto');
        $pos->assertSee('Marca');
        $pos->assertSee('Modelo');
        $pos->assertSee('Año');
        $pos->assertSee('Buscar vehículo');
        $pos->assertSee('pos-embedded');
    }
}
