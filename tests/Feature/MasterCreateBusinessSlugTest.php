<?php

namespace Tests\Feature;

use App\Filament\Master\Resources\BusinessResource\Pages\CreateBusiness;
use App\Models\Business;
use App\Models\User;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Livewire\Livewire;
use Tests\TestCase;

class MasterCreateBusinessSlugTest extends TestCase
{
    use DatabaseTransactions;

    public function test_unique_slug_avoids_taken_codes(): void
    {
        $this->createExistingBusinessWithSlug('aa');

        $this->assertTrue(Business::query()->where('slug', 'aa')->exists());
        $this->assertSame('aa-1', Business::uniqueSlug('aa'));
        $this->assertSame('ferreteria-milan', Business::uniqueSlug('ferreteria milan'));
    }

    public function test_saving_duplicate_slug_does_not_throw(): void
    {
        $this->createExistingBusinessWithSlug('aa');
        $owner = User::factory()->create();

        $business = Business::create([
            'name' => 'Ferretería Milan prueba',
            'slug' => 'aa',
            'type' => 'ferreteria',
            'owner_id' => $owner->id,
            'is_active' => true,
        ]);

        $this->assertSame('aa-1', $business->slug);
        $this->assertTrue(Business::query()->where('slug', 'aa')->exists());
    }

    public function test_master_form_rejects_taken_slug_without_500(): void
    {
        $this->createExistingBusinessWithSlug('aa');
        $master = User::factory()->create([
            'is_master_admin' => true,
            'is_active' => true,
        ]);
        $this->withoutVite();
        $this->actingAs($master);
        filament()->setCurrentPanel(filament()->getPanel('master'));

        Livewire::test(CreateBusiness::class)
            ->fillForm([
                'name' => 'ferreteria milan',
                'slug' => 'aa',
                'type' => 'ferreteria',
                'subscription_plan' => 'esencial',
                'extra_seats' => 0,
                'is_active' => true,
                'owner_name' => 'millan',
                'owner_email' => 'ferreteriamilan-test@comerciosrd.local',
                'owner_password' => 'Password123!',
            ])
            ->call('create')
            ->assertHasFormErrors(['slug']);

        $this->assertFalse(
            Business::query()->where('name', 'ferreteria milan')->exists()
        );
    }

    public function test_master_form_creates_ferreteria_with_free_slug(): void
    {
        $this->createExistingBusinessWithSlug('aa');
        $master = User::factory()->create([
            'is_master_admin' => true,
            'is_active' => true,
        ]);
        $this->withoutVite();
        $this->actingAs($master);
        filament()->setCurrentPanel(filament()->getPanel('master'));

        Livewire::test(CreateBusiness::class)
            ->fillForm([
                'name' => 'ferreteria milan',
                'slug' => 'ferreteria-milan',
                'type' => 'ferreteria',
                'phone' => '8097195400',
                'email' => 'ferreteriamilan-test@comerciosrd.local',
                'address' => 'amsm km28',
                'city' => 'santo domingo',
                'subscription_plan' => 'esencial',
                'extra_seats' => 0,
                'is_active' => true,
                'owner_name' => 'millan',
                'owner_email' => 'ferreteriamilan-test@comerciosrd.local',
                'owner_password' => 'Password123!',
            ])
            ->call('create')
            ->assertHasNoFormErrors();

        $shop = Business::query()->where('slug', 'ferreteria-milan')->first();
        $this->assertNotNull($shop);
        $this->assertSame('ferreteria', $shop->type->value ?? (string) $shop->type);
        $this->assertTrue(
            User::query()->where('email', 'ferreteriamilan-test@comerciosrd.local')->exists()
        );
        $this->assertTrue(Business::query()->where('slug', 'aa')->exists());
    }

    private function createExistingBusinessWithSlug(string $slug): Business
    {
        $owner = User::factory()->create(['is_active' => true]);

        return Business::create([
            'name' => 'Negocio existente '.$slug,
            'slug' => $slug,
            'type' => 'colmado',
            'owner_id' => $owner->id,
            'is_active' => true,
        ]);
    }
}
