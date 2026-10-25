<?php

namespace Tests\Feature;

use App\Enums\BusinessType;
use App\Enums\InventoryMovementType;
use App\Models\Business;
use App\Models\InventoryMovement;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\Product;
use App\Models\ProductKitItem;
use App\Models\User;
use App\Services\SaleService;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Tests\TestCase;

class CafeteriaKitchenInventoryTest extends TestCase
{
    use DatabaseTransactions;

    /**
     * @return array{user: User, business: Business, pan: Product, carne: Product, burger: Product}
     */
    protected function seedCafe(): array
    {
        $user = User::factory()->create();
        $business = Business::create([
            'name' => 'Café Prueba',
            'type' => BusinessType::Cafeteria,
            'owner_id' => $user->id,
            'is_active' => true,
        ]);
        $user->businesses()->attach($business->id, ['role' => 'owner', 'is_active' => true]);
        $this->actingAs($user);
        app()->instance('currentBusinessId', $business->id);
        app()->instance('currentBusiness', $business);
        session(['current_business_id' => $business->id]);

        $pan = Product::withoutBusinessScope()->forceCreate([
            'business_id' => $business->id,
            'name' => 'Pan',
            'price' => 10,
            'cost' => 5,
            'stock' => 20,
            'min_stock' => 2,
            'is_active' => true,
            'track_stock' => true,
            'is_kit' => false,
            'inventory_behavior' => 'deduct_on_sale',
        ]);
        $carne = Product::withoutBusinessScope()->forceCreate([
            'business_id' => $business->id,
            'name' => 'Carne',
            'price' => 40,
            'cost' => 25,
            'stock' => 15,
            'min_stock' => 2,
            'is_active' => true,
            'track_stock' => true,
            'is_kit' => false,
            'inventory_behavior' => 'deduct_on_sale',
        ]);
        $burger = Product::withoutBusinessScope()->forceCreate([
            'business_id' => $business->id,
            'name' => 'Hamburguesa',
            'price' => 180,
            'cost' => 0,
            'stock' => 0,
            'is_active' => true,
            'track_stock' => false,
            'is_kit' => true,
            'inventory_behavior' => 'do_not_deduct',
        ]);
        ProductKitItem::withoutBusinessScope()->forceCreate([
            'business_id' => $business->id,
            'kit_product_id' => $burger->id,
            'component_product_id' => $pan->id,
            'quantity' => 1,
        ]);
        ProductKitItem::withoutBusinessScope()->forceCreate([
            'business_id' => $business->id,
            'kit_product_id' => $burger->id,
            'component_product_id' => $carne->id,
            'quantity' => 1,
        ]);

        return compact('user', 'business', 'pan', 'carne', 'burger');
    }

    protected function makeOrder(Business $business, User $user, Product $plate, float $qty = 1): Order
    {
        $order = Order::withoutBusinessScope()->forceCreate([
            'business_id' => $business->id,
            'order_number' => Order::generateOrderNumber($business->id),
            'order_type' => 'por_numero',
            'status' => 'pendiente',
            'created_by' => $user->id,
            'subtotal' => $plate->price * $qty,
            'tax' => 0,
            'total' => $plate->price * $qty,
        ]);
        OrderItem::withoutBusinessScope()->forceCreate([
            'business_id' => $business->id,
            'order_id' => $order->id,
            'product_id' => $plate->id,
            'product_name' => $plate->name,
            'unit_price' => $plate->price,
            'quantity' => $qty,
            'subtotal' => $plate->price * $qty,
        ]);

        return $order->fresh('items');
    }

    public function test_sending_to_kitchen_deducts_recipe_ingredients(): void
    {
        ['business' => $business, 'user' => $user, 'pan' => $pan, 'carne' => $carne, 'burger' => $burger] = $this->seedCafe();
        $order = $this->makeOrder($business, $user, $burger);

        $order->update(['status' => 'en_preparacion']);

        $this->assertNotNull($order->fresh()->kitchen_issued_at);
        $this->assertSame(19.0, (float) $pan->fresh()->stock);
        $this->assertSame(14.0, (float) $carne->fresh()->stock);
        $this->assertTrue(
            InventoryMovement::withoutBusinessScope()
                ->where('business_id', $business->id)
                ->where('reference_id', $order->id)
                ->where('type', InventoryMovementType::AdjustmentOut->value)
                ->exists()
        );
    }

    public function test_cancel_after_kitchen_restores_ingredients(): void
    {
        ['business' => $business, 'user' => $user, 'pan' => $pan, 'burger' => $burger] = $this->seedCafe();
        $order = $this->makeOrder($business, $user, $burger);
        $order->update(['status' => 'en_preparacion']);
        $order->update(['status' => 'cancelada']);

        $this->assertNull($order->fresh()->kitchen_issued_at);
        $this->assertSame(20.0, (float) $pan->fresh()->stock);
    }

    public function test_charging_after_kitchen_does_not_deduct_twice(): void
    {
        ['business' => $business, 'user' => $user, 'pan' => $pan, 'carne' => $carne, 'burger' => $burger] = $this->seedCafe();
        $order = $this->makeOrder($business, $user, $burger);
        $order->update(['status' => 'en_preparacion']);

        app(SaleService::class)->convertFromOrder($order->fresh('items'), [
            'payment_method' => 'efectivo',
            'amount_paid' => 250,
            'tax_rate' => 0,
        ]);

        $this->assertSame(19.0, (float) $pan->fresh()->stock);
        $this->assertSame(14.0, (float) $carne->fresh()->stock);
        $this->assertSame('completada', (string) $order->fresh()->status);
    }

    public function test_hardware_order_status_does_not_touch_kitchen_inventory(): void
    {
        $user = User::factory()->create();
        $business = Business::create([
            'name' => 'Ferretería Control',
            'type' => BusinessType::Ferreteria,
            'owner_id' => $user->id,
            'is_active' => true,
        ]);
        $user->businesses()->attach($business->id, ['role' => 'owner', 'is_active' => true]);
        $this->actingAs($user);
        app()->instance('currentBusinessId', $business->id);
        app()->instance('currentBusiness', $business);

        $prod = Product::withoutBusinessScope()->forceCreate([
            'business_id' => $business->id,
            'name' => 'Tornillo',
            'price' => 5,
            'cost' => 1,
            'stock' => 50,
            'is_active' => true,
            'track_stock' => true,
            'inventory_behavior' => 'deduct_on_sale',
        ]);
        $order = $this->makeOrder($business, $user, $prod);
        $order->update(['status' => 'en_preparacion']);

        $this->assertNull($order->fresh()->kitchen_issued_at);
        $this->assertSame(50.0, (float) $prod->fresh()->stock);
    }

    public function test_kitchen_board_moves_pending_to_preparing_to_ready(): void
    {
        ['business' => $business, 'user' => $user, 'burger' => $burger] = $this->seedCafe();
        $order = $this->makeOrder($business, $user, $burger);

        $page = app(\App\Filament\Pages\CafeteriaDashboard::class);
        $page->startPreparing((string) $order->id);
        $this->assertSame('en_preparacion', (string) $order->fresh()->status);

        $page->markReady((string) $order->id);
        $this->assertSame('lista', (string) $order->fresh()->status);

        $salida = app(\App\Filament\Pages\CafeteriaPickup::class);
        $salida->sendOut((string) $order->id);
        $this->assertSame('completada', (string) $order->fresh()->status);
    }

    public function test_pos_sale_creates_kitchen_ticket(): void
    {
        ['user' => $user, 'business' => $business, 'burger' => $burger] = $this->seedCafe();

        $sale = app(\App\Contracts\RecordsSale::class)->create([
            'business_id' => $business->id,
            'user_id' => $user->id,
            'payment_method' => 'efectivo',
            'amount_paid' => 300,
            'tax_rate' => 0,
            'items' => [[
                'product_id' => $burger->id,
                'quantity' => 1,
                'unit_price' => 180,
            ]],
        ]);

        $ticket = \App\Models\Order::withoutBusinessScope()
            ->where('converted_sale_id', $sale->id)
            ->first();

        $this->assertNotNull($ticket);
        $this->assertSame('pendiente', (string) $ticket->status);
        $this->assertNotNull($ticket->kitchen_issued_at);
        $this->assertSame(1, $ticket->items()->count());
    }

    public function test_cafeteria_implies_kits_module(): void
    {
        ['business' => $business] = $this->seedCafe();

        $this->assertTrue($business->isCafeteria());
        $this->assertTrue($business->hasModule('kits'));
        $this->assertTrue($business->hasModule('inventory_movements'));
    }
}
