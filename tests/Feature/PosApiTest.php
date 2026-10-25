<?php

namespace Tests\Feature;

use App\Enums\BusinessType;
use App\Models\Business;
use App\Models\CashSession;
use App\Models\Product;
use App\Models\Quote;
use App\Models\Sale;
use App\Models\User;
use App\Services\QuoteService;
use App\Services\TwoFactorService;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Str;
use Tests\TestCase;

class PosApiTest extends TestCase
{
    use DatabaseTransactions;

    /**
     * @return array{user: User, business: Business, product: Product}
     */
    protected function seedPos(): array
    {
        $user = User::factory()->create();
        $business = Business::create([
            'name' => 'Colmado Caja API',
            'type' => BusinessType::Colmado,
            'owner_id' => $user->id,
            'is_active' => true,
        ]);
        $user->businesses()->attach($business->id, ['role' => 'owner', 'is_active' => true]);
        $this->actingAs($user);

        $product = Product::withoutBusinessScope()->forceCreate([
            'business_id' => $business->id,
            'name' => 'Aceite 1L',
            'sku' => 'ACE-1L',
            'barcode' => '746000111222',
            'price' => 100,
            'cost' => 60,
            'stock' => 20,
            'min_stock' => 1,
            'is_active' => true,
            'track_stock' => true,
            'inventory_behavior' => 'deduct_on_sale',
        ]);

        return compact('user', 'business', 'product');
    }

    public function test_caja_page_loads_for_owner(): void
    {
        ['business' => $business] = $this->seedPos();

        $this->get('/caja/'.$business->slug)
            ->assertOk()
            ->assertSee('Escanear', false);
    }

    public function test_guest_caja_redirects_to_admin_login(): void
    {
        $this->get('/caja/gg')
            ->assertRedirect('/admin/login');

        $this->get('/login')
            ->assertRedirect('/admin/login');
    }

    public function test_forbidden_filament_page_does_not_poison_caja(): void
    {
        ['business' => $business] = $this->seedPos();
        $this->withoutVite();

        $this->get('/admin/'.$business->slug.'/prescriptions');

        $this->get('/caja/'.$business->slug)
            ->assertOk()
            ->assertSee('Escanear', false);
    }

    public function test_search_returns_only_this_business(): void
    {
        ['business' => $business] = $this->seedPos();

        $this->getJson('/caja/'.$business->slug.'/api/search?q=Aceite')
            ->assertOk()
            ->assertJsonPath('ok', true)
            ->assertJsonFragment(['name' => 'Aceite 1L']);
    }

    public function test_scan_barcode_returns_cart_line(): void
    {
        ['business' => $business, 'product' => $product] = $this->seedPos();
        $product->forceFill(['image' => 'products/aceite.jpg'])->save();

        $this->postJson('/caja/'.$business->slug.'/api/scan', ['code' => '746000111222'])
            ->assertOk()
            ->assertJsonPath('action', 'add')
            ->assertJsonPath('item.barcode', '746000111222')
            ->assertJsonPath('item.image_url', $product->imageUrl());
    }

    public function test_checkout_creates_sale_without_livewire_state(): void
    {
        ['business' => $business, 'product' => $product] = $this->seedPos();

        $this->postJson('/caja/'.$business->slug.'/api/cash/open', [
            'opening_amount' => 500,
        ])->assertOk()->assertJsonPath('ok', true);

        $response = $this->postJson('/caja/'.$business->slug.'/api/checkout', [
            'items' => [[
                'product_id' => $product->id,
                'qty' => 2,
                'price' => 1,
            ]],
            'payment_method' => 'efectivo',
            'amount_tendered' => 236,
            'tax_rate' => 99,
        ]);

        $response->assertOk()->assertJsonPath('ok', true);

        $saleId = $response->json('sale_id');
        $this->assertNotEmpty($saleId);

        $sale = Sale::withoutBusinessScope()->whereKey($saleId)->first();
        $this->assertNotNull($sale);
        $this->assertSame((string) $business->id, (string) $sale->business_id);
        $this->assertEqualsWithDelta(236.0, (float) $sale->total, 0.05);
        $this->assertEqualsWithDelta(18.0, (float) $sale->tax_rate, 0.01);
        $this->assertEqualsWithDelta(100.0, (float) $sale->items()->first()->unit_price, 0.01);
    }

    public function test_pos_quote_uses_catalog_price_and_business_tax_rate(): void
    {
        ['business' => $business, 'product' => $product] = $this->seedPos();
        app()->instance('currentBusinessId', $business->id);
        app()->instance('currentBusiness', $business);

        $quote = app(QuoteService::class)->createFromPos($business, [
            'items' => [[
                'product_id' => $product->id,
                'qty' => 2,
                'price' => 0.01,
            ]],
            'tax_rate' => 99,
        ]);

        $this->assertEqualsWithDelta(100.0, (float) $quote->items()->first()->unit_price, 0.01);
        $this->assertEqualsWithDelta(18.0, (float) $quote->tax_rate, 0.01);
        $this->assertEqualsWithDelta(236.0, (float) $quote->total, 0.05);
    }

    public function test_receipt_routes_require_two_factor_when_user_has_it_enabled(): void
    {
        ['user' => $user, 'business' => $business] = $this->seedPos();
        config()->set('security.two_factor.enabled', true);

        $user->forceFill([
            'two_factor_secret' => app(TwoFactorService::class)->encryptSecret('TESTSECRET'),
            'two_factor_confirmed_at' => now(),
        ])->save();

        app()->instance('currentBusinessId', $business->id);
        app()->instance('currentBusiness', $business);
        session()->put('current_business_id', $business->id);

        $sale = Sale::withoutBusinessScope()->create([
            'business_id' => $business->id,
            'subtotal' => 100,
            'tax' => 18,
            'discount' => 0,
            'total' => 118,
            'amount_paid' => 118,
        ]);

        $this->get('/print/ticket/'.$sale->id)
            ->assertRedirect(route('two-factor.challenge'));

        $this->get('/admin/'.$business->slug.'/receipts/sales/'.$sale->id.'/print')
            ->assertRedirect(route('two-factor.challenge'));
    }

    public function test_two_factor_verification_is_rate_limited(): void
    {
        $user = User::factory()->create();
        $this->actingAs($user);

        for ($attempt = 0; $attempt < 5; $attempt++) {
            $this->post(route('two-factor.verify'), ['code' => '000000'])
                ->assertRedirect();
        }

        $this->post(route('two-factor.verify'), ['code' => '000000'])
            ->assertTooManyRequests();
    }

    public function test_foreign_tenant_cannot_hit_caja_api(): void
    {
        ['business' => $businessA] = $this->seedPos();

        $userB = User::factory()->create();
        $businessB = Business::create([
            'name' => 'Otro',
            'type' => BusinessType::Colmado,
            'owner_id' => $userB->id,
            'is_active' => true,
        ]);
        $userB->businesses()->attach($businessB->id, ['role' => 'owner', 'is_active' => true]);

        $this->actingAs($userB)
            ->getJson('/caja/'.$businessA->slug.'/api/search?q=Aceite')
            ->assertForbidden();
    }

    public function test_cashier_apply_sends_to_caja_route(): void
    {
        $user = User::factory()->create();
        $cashier = User::factory()->create();
        $business = Business::create([
            'name' => 'Caja Destino',
            'type' => BusinessType::Colmado,
            'owner_id' => $user->id,
            'is_active' => true,
        ]);
        $cashier->businesses()->attach($business->id, ['role' => 'cashier', 'is_active' => true]);
        $this->actingAs($cashier);

        $result = app(\App\Services\AuthContextResolver::class)->apply($cashier, $business);

        $this->assertSame(\App\Services\AuthContextResolver::STATUS_OK, $result['status']);
        $this->assertStringContainsString('/caja/'.$business->slug, (string) $result['panel_url']);
    }

    /**
     * @return array{owner: User, cashier: User, business: Business}
     */
    protected function seedCashierPos(): array
    {
        $owner = User::factory()->create();
        $cashier = User::factory()->create();
        $business = Business::create([
            'name' => 'Repuesto Cajero Caja',
            'type' => BusinessType::Repuestos,
            'owner_id' => $owner->id,
            'is_active' => true,
        ]);
        $cashier->businesses()->attach($business->id, ['role' => 'cashier', 'is_active' => true]);
        $this->actingAs($cashier);

        return compact('owner', 'cashier', 'business');
    }

    public function test_cashier_caja_shows_open_cash_controls(): void
    {
        ['business' => $business] = $this->seedCashierPos();

        $this->get('/caja/'.$business->slug)
            ->assertOk()
            ->assertSee('Abrir caja', false)
            ->assertDontSee('Tu caja está cerrada', false)
            ->assertSee('Monto de apertura', false)
            ->assertSee('Escribe la marca', false)
            ->assertDontSee('>Toyota<', false);
    }

    public function test_cashier_can_open_and_close_cash(): void
    {
        ['business' => $business, 'cashier' => $cashier] = $this->seedCashierPos();

        $open = $this->postJson('/caja/'.$business->slug.'/api/cash/open', [
            'opening_amount' => 1500.50,
            'opening_notes' => 'Fondo inicial',
        ]);

        $open->assertOk()
            ->assertJsonPath('ok', true)
            ->assertJsonPath('open_cash', true)
            ->assertJsonPath('cash.open', true)
            ->assertJsonPath('cash.can_close', true)
            ->assertJsonPath('cash.can_open', false)
            ->assertJsonPath('cash.opening_amount', 1500.5)
            ->assertJsonPath('cash.opened_by', $cashier->name);

        $session = CashSession::withoutBusinessScope()
            ->where('business_id', $business->id)
            ->where('status', CashSession::STATUS_OPEN)
            ->first();
        $this->assertNotNull($session);
        $this->assertEqualsWithDelta(1500.50, (float) $session->opening_amount, 0.01);

        $this->postJson('/caja/'.$business->slug.'/api/cash/open', [
            'opening_amount' => 10,
        ])->assertStatus(422)->assertJsonPath('ok', false);

        $close = $this->postJson('/caja/'.$business->slug.'/api/cash/close', [
            'closing_amount' => 1500.50,
        ]);

        $close->assertOk()
            ->assertJsonPath('ok', true)
            ->assertJsonPath('open_cash', false)
            ->assertJsonPath('cash.open', false)
            ->assertJsonPath('cash.can_open', true)
            ->assertJsonPath('difference', 0);

        $this->assertSame(
            CashSession::STATUS_CLOSED,
            CashSession::withoutBusinessScope()->whereKey($session->id)->value('status')
        );
    }

    public function test_efectivo_checkout_requires_open_cash(): void
    {
        ['business' => $business, 'product' => $product] = $this->seedPos();

        $this->postJson('/caja/'.$business->slug.'/api/checkout', [
            'items' => [[
                'product_id' => $product->id,
                'qty' => 1,
                'price' => 100,
            ]],
            'payment_method' => 'efectivo',
            'amount_tendered' => 118,
            'tax_rate' => 18,
        ])->assertStatus(422)
            ->assertJsonFragment(['message' => 'Abre la caja antes de cobrar en efectivo.']);
    }

    public function test_seller_cannot_open_cash(): void
    {
        $owner = User::factory()->create();
        $seller = User::factory()->create();
        $business = Business::create([
            'name' => 'Preventa Sin Caja',
            'type' => BusinessType::Repuestos,
            'owner_id' => $owner->id,
            'is_active' => true,
        ]);
        $seller->businesses()->attach($business->id, ['role' => 'seller', 'is_active' => true]);
        $this->actingAs($seller);

        $this->postJson('/caja/'.$business->slug.'/api/cash/open', [
            'opening_amount' => 100,
        ])->assertForbidden();
    }

    public function test_cashier_can_quote_then_charge_from_caja(): void
    {
        ['business' => $business] = $this->seedCashierPos();

        $product = Product::withoutBusinessScope()->forceCreate([
            'business_id' => $business->id,
            'name' => 'Filtro de aceite',
            'sku' => 'FIL-001',
            'barcode' => '746000333444',
            'price' => 200,
            'cost' => 80,
            'stock' => 10,
            'min_stock' => 1,
            'is_active' => true,
            'track_stock' => true,
            'inventory_behavior' => 'deduct_on_sale',
        ]);

        $page = $this->get('/caja/'.$business->slug);
        $page->assertOk()->assertSee('Cotizar', false)->assertSee('Cotizaciones pendientes', false);

        $save = $this->postJson('/caja/'.$business->slug.'/api/quotes', [
            'items' => [[
                'product_id' => $product->id,
                'qty' => 2,
                'price' => 0.01,
                'name' => 'Filtro de aceite',
            ]],
            'customer_name' => 'Taller Pérez',
            'tax_rate' => 99,
        ]);
        $save->assertOk()->assertJsonPath('ok', true);
        $quoteId = $save->json('quote_id');
        $this->assertNotEmpty($quoteId);

        $quote = Quote::withoutBusinessScope()->whereKey($quoteId)->first();
        $this->assertNotNull($quote);
        $this->assertSame('draft', $quote->status);
        $this->assertSame('Taller Pérez', $quote->customer_name);
        $this->assertNull($quote->converted_sale_id);
        $this->assertEqualsWithDelta(200.0, (float) $quote->items()->first()->unit_price, 0.01);
        $this->assertEqualsWithDelta(18.0, (float) $quote->tax_rate, 0.01);

        $this->getJson('/caja/'.$business->slug.'/api/quotes')
            ->assertOk()
            ->assertJsonFragment(['id' => $quoteId]);

        $load = $this->getJson('/caja/'.$business->slug.'/api/quotes/'.$quoteId);
        $load->assertOk()
            ->assertJsonPath('ok', true)
            ->assertJsonPath('quote.code', $quote->code)
            ->assertJsonPath('items.0.product_id', (string) $product->id);

        $this->postJson('/caja/'.$business->slug.'/api/cash/open', [
            'opening_amount' => 1000,
        ])->assertOk();

        $charge = $this->postJson('/caja/'.$business->slug.'/api/checkout', [
            'items' => [[
                'product_id' => $product->id,
                'qty' => 2,
                'price' => 0.01,
            ]],
            'payment_method' => 'efectivo',
            'amount_tendered' => 472,
            'tax_rate' => 0,
            'quote_id' => $quoteId,
        ]);
        $charge->assertOk()->assertJsonPath('ok', true);

        $quote->refresh();
        $this->assertSame('converted', $quote->status);
        $this->assertSame($charge->json('sale_id'), (string) $quote->converted_sale_id);
        $sale = Sale::withoutBusinessScope()->whereKey($charge->json('sale_id'))->firstOrFail();
        $this->assertEqualsWithDelta(472.0, (float) $sale->total, 0.05);
        $this->assertEqualsWithDelta(200.0, (float) $sale->items()->first()->unit_price, 0.01);
    }

    public function test_two_cashiers_open_their_own_tills(): void
    {
        $owner = User::factory()->create();
        $a = User::factory()->create(['name' => 'Cajero Uno']);
        $b = User::factory()->create(['name' => 'Cajero Dos']);
        $business = Business::create([
            'name' => 'Dos Cajas',
            'type' => BusinessType::Repuestos,
            'owner_id' => $owner->id,
            'is_active' => true,
        ]);
        $a->businesses()->attach($business->id, ['role' => 'cashier', 'is_active' => true]);
        $b->businesses()->attach($business->id, ['role' => 'cashier', 'is_active' => true]);

        $product = Product::withoutBusinessScope()->forceCreate([
            'business_id' => $business->id,
            'name' => 'Bujía',
            'sku' => 'BUJ-1',
            'barcode' => '746000555666',
            'price' => 100,
            'cost' => 40,
            'stock' => 20,
            'is_active' => true,
            'track_stock' => true,
            'inventory_behavior' => 'deduct_on_sale',
        ]);

        $this->actingAs($a)
            ->postJson('/caja/'.$business->slug.'/api/cash/open', ['opening_amount' => 100])
            ->assertOk()
            ->assertJsonPath('cash.opened_by', 'Cajero Uno');

        $this->actingAs($b)
            ->postJson('/caja/'.$business->slug.'/api/checkout', [
                'items' => [['product_id' => $product->id, 'qty' => 1, 'price' => 100]],
                'payment_method' => 'efectivo',
                'amount_tendered' => 118,
                'tax_rate' => 18,
            ])
            ->assertStatus(422)
            ->assertJsonFragment(['message' => 'Abre la caja antes de cobrar en efectivo.']);

        $this->actingAs($b)
            ->postJson('/caja/'.$business->slug.'/api/cash/open', ['opening_amount' => 250])
            ->assertOk()
            ->assertJsonPath('ok', true)
            ->assertJsonPath('cash.opening_amount', 250)
            ->assertJsonPath('cash.opened_by', 'Cajero Dos');

        $this->assertSame(
            2,
            CashSession::withoutBusinessScope()
                ->where('business_id', $business->id)
                ->where('status', CashSession::STATUS_OPEN)
                ->count()
        );

        $this->actingAs($b)
            ->postJson('/caja/'.$business->slug.'/api/checkout', [
                'items' => [['product_id' => $product->id, 'qty' => 1, 'price' => 100]],
                'payment_method' => 'efectivo',
                'amount_tendered' => 118,
                'tax_rate' => 18,
            ])
            ->assertOk();

        $sessionA = CashSession::withoutBusinessScope()
            ->where('business_id', $business->id)
            ->where('user_id', $a->id)
            ->where('status', CashSession::STATUS_OPEN)
            ->first();
        $sessionB = CashSession::withoutBusinessScope()
            ->where('business_id', $business->id)
            ->where('user_id', $b->id)
            ->where('status', CashSession::STATUS_OPEN)
            ->first();
        $this->assertNotNull($sessionA);
        $this->assertNotNull($sessionB);
        $this->assertEqualsWithDelta(100.0, (float) $sessionA->opening_amount, 0.01);

        $this->actingAs($b)
            ->postJson('/caja/'.$business->slug.'/api/cash/close', ['closing_amount' => 368])
            ->assertOk()
            ->assertJsonPath('open_cash', false);

        $this->assertSame(
            CashSession::STATUS_OPEN,
            CashSession::withoutBusinessScope()->whereKey($sessionA->id)->value('status')
        );
        $this->assertSame(
            CashSession::STATUS_CLOSED,
            CashSession::withoutBusinessScope()->whereKey($sessionB->id)->value('status')
        );
    }

    public function test_colmado_caja_does_not_create_quotes(): void
    {
        ['business' => $business, 'product' => $product] = $this->seedPos();

        $this->postJson('/caja/'.$business->slug.'/api/quotes', [
            'items' => [[
                'product_id' => $product->id,
                'qty' => 1,
                'price' => 100,
            ]],
        ])->assertStatus(422);
    }
}
