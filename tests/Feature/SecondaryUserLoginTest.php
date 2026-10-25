<?php

namespace Tests\Feature;

use App\Enums\BusinessType;
use App\Filament\Auth\Login;
use App\Models\Business;
use App\Models\User;
use App\Services\AuthContextResolver;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Livewire\Livewire;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class SecondaryUserLoginTest extends TestCase
{
    use DatabaseTransactions;

    /**
     * @return array{owner: User, business: Business}
     */
    protected function shop(): array
    {
        $owner = User::factory()->create(['email' => 'dueno-sec-'.uniqid().'@test.rd']);
        $business = Business::create([
            'name' => 'Local Secundario Login',
            'type' => BusinessType::Repuestos,
            'owner_id' => $owner->id,
            'is_active' => true,
        ]);
        $owner->businesses()->attach($business->id, ['role' => 'owner', 'is_active' => true]);

        return compact('owner', 'business');
    }

    protected function staff(Business $business, string $role): User
    {
        $user = User::factory()->create([
            'email' => $role.'-sec-'.uniqid().'@test.rd',
            'password' => 'password',
        ]);
        $user->businesses()->attach($business->id, [
            'role' => $role,
            'is_active' => true,
            'joined_at' => now(),
        ]);

        return $user->fresh();
    }

    /**
     * @return array<int, array{0: string, 1: string}>
     */
    public static function secondaryRoles(): array
    {
        return [
            ['admin', '/admin/'],
            ['seller', 'preventa'],
            ['cashier', 'caja'],
            ['inventory', 'products'],
            ['supervisor', '/admin/'],
            ['viewer', '/admin/'],
        ];
    }

    #[DataProvider('secondaryRoles')]
    public function test_secondary_user_login_does_not_404(string $role, string $urlHint): void
    {
        ['business' => $business] = $this->shop();
        $user = $this->staff($business, $role);

        $this->withoutVite();
        $this->get('/admin/login')->assertOk();

        filament()->setCurrentPanel(filament()->getPanel('admin'));

        $component = Livewire::test(Login::class)
            ->fillForm([
                'email' => $user->email,
                'password' => 'password',
                'business_slug' => $business->slug,
            ])
            ->call('authenticate');

        $errors = $component->errors()->toArray();
        $this->assertSame([], $errors, 'Login de '.$role.' falló: '.json_encode($errors));
        $component->assertRedirect();

        $dest = session(AuthContextResolver::SESSION_PANEL_DESTINATION);
        $this->assertIsString($dest);
        $this->assertNotSame('', $dest);
        $this->assertStringNotContainsString('/admin/login', $dest);

        if ($urlHint !== '') {
            $this->assertTrue(
                str_contains($dest, $urlHint),
                "Destino de {$role} debería incluir '{$urlHint}': {$dest}"
            );
        }

        $afterLogin = $this->get($dest);
        $this->assertNotSame(
            404,
            $afterLogin->getStatusCode(),
            "{$role} llegó a 404 en {$dest}"
        );

        $home = $this->get('/admin');
        $this->assertNotSame(404, $home->getStatusCode(), "{$role} /admin dio 404");
        $this->assertNotSame(500, $home->getStatusCode(), "{$role} /admin dio 500");

        $tenantHome = $this->get('/admin/'.$business->slug);
        $this->assertNotSame(
            404,
            $tenantHome->getStatusCode(),
            "{$role} /admin/{$business->slug} dio 404"
        );
    }

    public function test_secondary_http_home_is_not_404(): void
    {
        ['business' => $business] = $this->shop();
        $this->withoutVite();

        foreach (['admin', 'seller', 'cashier', 'inventory', 'viewer'] as $role) {
            $user = $this->staff($business, $role);
            $this->actingAs($user);
            $ctx = app(AuthContextResolver::class)->apply($user, $business);
            $this->assertSame(AuthContextResolver::STATUS_OK, $ctx['status'], $role);
            $this->assertNotEmpty($ctx['panel_url'], $role);

            $dest = $this->get($ctx['panel_url']);
            $this->assertNotSame(404, $dest->getStatusCode(), "{$role} dest {$ctx['panel_url']} 404");
            $this->assertNotSame(500, $dest->getStatusCode(), "{$role} dest {$ctx['panel_url']} 500 body=".substr($dest->getContent(), 0, 300));

            $admin = $this->get('/admin');
            $this->assertNotSame(404, $admin->getStatusCode(), "{$role} /admin 404");
            $this->assertNotSame(500, $admin->getStatusCode(), "{$role} /admin 500");

            $tenant = $this->get('/admin/'.$business->slug);
            $this->assertNotSame(404, $tenant->getStatusCode(), "{$role} tenant home 404");
            $this->assertNotSame(500, $tenant->getStatusCode(), "{$role} tenant home 500");
        }
    }

    public function test_seller_login_lands_on_preventa_ok(): void
    {
        ['business' => $business] = $this->shop();
        $seller = $this->staff($business, 'seller');

        $this->withoutVite();
        $this->get('/admin/login')->assertOk();

        Livewire::test(Login::class)
            ->fillForm([
                'email' => $seller->email,
                'password' => 'password',
                'business_slug' => $business->slug,
            ])
            ->call('authenticate')
            ->assertHasNoFormErrors()
            ->assertRedirect('/admin/'.$business->slug.'/preventa');

        $this->get('/admin/'.$business->slug.'/preventa')->assertOk();
    }

    public function test_cashier_login_lands_on_caja_ok(): void
    {
        ['business' => $business] = $this->shop();
        $cashier = $this->staff($business, 'cashier');

        $this->withoutVite();
        $this->get('/admin/login')->assertOk();

        Livewire::test(Login::class)
            ->fillForm([
                'email' => $cashier->email,
                'password' => 'password',
                'business_slug' => $business->slug,
            ])
            ->call('authenticate')
            ->assertHasNoFormErrors()
            ->assertRedirect('/caja/'.$business->slug);

        $this->get('/caja/'.$business->slug)->assertOk();
    }

    public function test_master_on_admin_login_is_logged_out_and_form_works(): void
    {
        ['owner' => $owner, 'business' => $business] = $this->shop();
        $master = User::factory()->create([
            'is_master_admin' => true,
            'is_active' => true,
        ]);

        $this->withoutVite();
        $this->actingAs($master);
        filament()->setCurrentPanel(filament()->getPanel('admin'));

        Livewire::test(Login::class)
            ->fillForm([
                'email' => $owner->email,
                'password' => 'password',
                'business_slug' => $business->slug,
            ])
            ->call('authenticate')
            ->assertHasNoFormErrors()
            ->assertRedirect();

        $this->assertAuthenticatedAs($owner);
    }

    public function test_failed_context_login_does_not_kill_csrf_for_retry(): void
    {
        $orphan = User::factory()->create([
            'email' => 'huerfano-login-'.uniqid().'@test.rd',
            'password' => 'password',
            'is_active' => true,
        ]);

        $this->withoutVite();
        $this->get('/admin/login')->assertOk();
        filament()->setCurrentPanel(filament()->getPanel('admin'));

        Livewire::test(Login::class)
            ->fillForm([
                'email' => $orphan->email,
                'password' => 'password',
            ])
            ->call('authenticate')
            ->assertHasFormErrors(['email']);

        $this->assertGuest();
        $this->get('/admin/login')->assertOk();
    }
}
