<?php

namespace Tests\Feature;

use App\Enums\BusinessType;
use App\Models\Business;
use App\Models\Product;
use App\Models\User;
use App\Support\BusinessRole;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Tests\TestCase;

/**
 * Capa 1 — Tests de aislamiento multi-tenant (fail-closed).
 * Usa transacciones (rollback) para no contaminar la BD local.
 */
class TenantIsolationTest extends TestCase
{
    use DatabaseTransactions;

    protected User $userA;

    protected User $userB;

    protected Business $businessA;

    protected Business $businessB;

    protected Product $productA;

    protected Product $productB;

    protected function setUp(): void
    {
        parent::setUp();

        $this->userA = User::factory()->create(['email' => 'a@test.rd']);
        $this->userB = User::factory()->create(['email' => 'b@test.rd']);

        $this->businessA = Business::create([
            'name' => 'Colmado A',
            'type' => BusinessType::Colmado,
            'owner_id' => $this->userA->id,
            'is_active' => true,
        ]);

        $this->businessB = Business::create([
            'name' => 'Ferretería B',
            'type' => BusinessType::Ferreteria,
            'owner_id' => $this->userB->id,
            'is_active' => true,
        ]);

        $this->userA->businesses()->attach($this->businessA->id, [
            'role' => 'owner',
            'is_active' => true,
        ]);

        $this->userB->businesses()->attach($this->businessB->id, [
            'role' => 'owner',
            'is_active' => true,
        ]);

        // forceCreate: business_id no es fillable (inyección solo vía trait/contexto)
        $this->productA = Product::withoutBusinessScope()->forceCreate([
            'business_id' => $this->businessA->id,
            'name' => 'Arroz A',
            'price' => 50,
            'cost' => 30,
            'stock' => 10,
            'min_stock' => 2,
        ]);

        $this->productB = Product::withoutBusinessScope()->forceCreate([
            'business_id' => $this->businessB->id,
            'name' => 'Cemento B',
            'price' => 400,
            'cost' => 300,
            'stock' => 5,
            'min_stock' => 1,
        ]);
    }

    protected function bindTenant(Business $business, User $user): void
    {
        $this->actingAs($user);
        session(['current_business_id' => $business->id]);
        app()->instance('currentBusinessId', $business->id);
        app()->instance('currentBusiness', $business);
        $user->forceFill(['current_business_id' => $business->id])->saveQuietly();
    }

    public function test_global_scope_hides_other_tenant_products(): void
    {
        $this->bindTenant($this->businessA, $this->userA);

        $ids = Product::query()->pluck('id')->all();

        $this->assertContains($this->productA->id, $ids);
        $this->assertNotContains($this->productB->id, $ids);
    }

    public function test_find_other_tenant_product_returns_null(): void
    {
        $this->bindTenant($this->businessA, $this->userA);

        $found = Product::query()->find($this->productB->id);

        $this->assertNull($found);
    }

    public function test_assert_belongs_to_current_business_throws_404(): void
    {
        $this->bindTenant($this->businessA, $this->userA);

        // Cargar producto B sin scope para simular IDOR
        $foreign = Product::withoutBusinessScope()->findOrFail($this->productB->id);

        $this->expectException(NotFoundHttpException::class);

        $foreign->assertBelongsToCurrentBusiness();
    }

    public function test_cannot_reassign_business_id_on_update(): void
    {
        $this->bindTenant($this->businessA, $this->userA);

        $product = Product::query()->findOrFail($this->productA->id);
        $product->business_id = $this->businessB->id;
        $product->name = 'Arroz A renombrado';
        $product->save();

        $product->refresh();

        $this->assertSame($this->productA->id, $product->id);
        $this->assertSame((string) $this->businessA->id, (string) $product->business_id);
        $this->assertSame('Arroz A renombrado', $product->name);
    }

    public function test_creating_product_forces_current_business_id(): void
    {
        $this->bindTenant($this->businessA, $this->userA);

        $product = Product::create([
            'business_id' => $this->businessB->id, // intento malicioso
            'name' => 'Inyectado',
            'price' => 10,
            'cost' => 5,
            'stock' => 1,
        ]);

        $this->assertSame((string) $this->businessA->id, (string) $product->business_id);
    }

    public function test_user_cannot_access_foreign_tenant(): void
    {
        $this->assertTrue($this->userA->canAccessTenant($this->businessA));
        $this->assertFalse($this->userA->canAccessTenant($this->businessB));
    }

    public function test_cashier_cannot_delete_products_via_rbac(): void
    {
        $cashier = User::factory()->create(['email' => 'cajero@test.rd']);
        $cashier->businesses()->attach($this->businessA->id, [
            'role' => 'cashier',
            'is_active' => true,
        ]);

        $this->bindTenant($this->businessA, $cashier);

        $this->assertTrue(BusinessRole::allows($cashier, 'sales.create', $this->businessA->id));
        $this->assertFalse(BusinessRole::allows($cashier, 'products.delete', $this->businessA->id));
        $this->assertFalse(BusinessRole::allows($cashier, 'expenses.create', $this->businessA->id));
    }

    public function test_strong_password_rule(): void
    {
        $rule = new \App\Rules\StrongPassword;
        $fails = false;

        $rule->validate('password', 'weak', function () use (&$fails) {
            $fails = true;
        });
        $this->assertTrue($fails);

        $fails = false;
        $rule->validate('password', 'Str0ng!Pass', function () use (&$fails) {
            $fails = true;
        });
        $this->assertFalse($fails);
    }

    public function test_legacy_business_type_aliases_are_normalized_to_supported_giros(): void
    {
        $this->assertSame(
            Business::defaultModulesFor(BusinessType::Farmacia->value),
            Business::defaultModulesFor('ffarmacia')
        );

        $this->assertSame(
            Business::defaultModulesFor(BusinessType::Repuestos->value),
            Business::defaultModulesFor('repuesto')
        );

        $this->assertSame(
            Business::defaultModulesFor(BusinessType::Repuestos->value),
            Business::defaultModulesFor('repuestos')
        );
    }
}
