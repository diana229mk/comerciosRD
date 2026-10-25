<?php

namespace Tests\Feature;

use App\Models\Business;
use App\Models\Customer;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\Product;
use App\Models\User;
use App\Services\SaleService;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Tests\TestCase;

class OrderQuoteFieldsTest extends TestCase
{
    use DatabaseTransactions;

    public function test_order_supports_quote_fields_for_repuestos_workflow(): void
    {
        $order = Order::make([
            'order_number' => 'COT-2026-00001',
            'quote_number' => 'COT-2026-00001',
            'valid_until' => now()->addDays(7),
            'status' => 'sent',
            'subtotal' => 1500,
            'tax' => 270,
            'total' => 1770,
            'notes' => 'Cotización para taller',
        ]);

        $this->assertSame('COT-2026-00001', $order->quote_number);
        $this->assertSame('sent', $order->status);
        $this->assertEquals(now()->addDays(7)->format('Y-m-d'), $order->valid_until->format('Y-m-d'));

        $item = OrderItem::make([
            'product_name' => 'Filtro de aceite',
            'oem_code' => 'OE-123',
            'quantity' => 2,
            'unit_price' => 750,
        ]);

        $this->assertSame('OE-123', $item->oem_code);
    }

    public function test_order_can_be_converted_to_sale(): void
    {
        $owner = User::factory()->create();
        $business = Business::create([
            'name' => 'Repuestos Test',
            'type' => 'repuestos',
            'owner_id' => $owner->id,
            'is_active' => true,
        ]);

        $owner->forceFill(['current_business_id' => $business->id])->saveQuietly();
        $this->actingAs($owner);
        session(['current_business_id' => $business->id]);
        app()->instance('currentBusinessId', $business->id);
        app()->instance('currentBusiness', $business);

        $customer = Customer::withoutBusinessScope()->forceCreate([
            'business_id' => $business->id,
            'name' => 'Taller Test',
            'phone' => '5550000',
            'email' => 'taller@test.rd',
            'is_active' => true,
        ]);
        $product = Product::withoutBusinessScope()->forceCreate([
            'business_id' => $business->id,
            'name' => 'Filtro de aceite',
            'price' => 150,
            'cost' => 100,
            'stock' => 20,
            'is_active' => true,
            'oem_code' => 'OE-900',
            'unit' => 'unidad',
        ]);

        $order = Order::withoutBusinessScope()->forceCreate([
            'business_id' => $business->id,
            'customer_id' => $customer->id,
            'order_number' => 'COT-2026-00002',
            'quote_number' => 'COT-2026-00002',
            'status' => 'accepted',
            'valid_until' => now()->addDays(7),
            'subtotal' => 300,
            'tax' => 54,
            'total' => 354,
            'notes' => 'Cotización aceptada',
            'created_by' => $owner->id,
        ]);

        OrderItem::withoutBusinessScope()->forceCreate([
            'business_id' => $business->id,
            'order_id' => $order->id,
            'product_id' => $product->id,
            'product_name' => $product->name,
            'oem_code' => 'OE-900',
            'unit' => 'unidad',
            'quantity' => 2,
            'unit_price' => 150,
            'subtotal' => 300,
        ]);

        $sale = app(SaleService::class)->convertFromOrder($order, [
            'payment_method' => 'efectivo',
            'amount_paid' => 354,
        ]);

        $this->assertNotNull($sale);
        $this->assertSame('completada', $sale->status->value ?? (string) $sale->status);
        $this->assertNull($sale->quote_id);
        $this->assertSame('converted', $order->fresh()->status);
    }
}
