<?php

namespace Tests\Feature;

use App\Enums\SaleStatus;
use App\Models\Business;
use App\Models\Product;
use App\Models\ProductBatch;
use App\Models\Sale;
use App\Models\SaleItem;
use App\Models\SaleItemBatch;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Carbon;
use Tests\TestCase;

class ReceiptPharmacyTest extends TestCase
{
    use DatabaseTransactions;

    public function test_pharmacy_ticket_shows_batch_and_expiry()
    {
        // Crear negocio tipo farmacia
        $business = Business::create([
            'name' => 'Farmacia Test',
            'type' => 'farmacia',
        ]);

        // Establecer tenant en el contenedor para los modelos que usan BelongsToBusiness
        app()->instance('currentBusinessId', $business->id);
        app()->instance('currentBusiness', $business);

        // Producto
        $product = Product::create([
            'name' => 'Aspirina',
            'price' => 12.5,
            'track_stock' => true,
            'inventory_behavior' => Product::BEHAVIOR_DEDUCT_ON_SALE,
        ]);

        // Lote
        $batch = ProductBatch::create([
            'business_id' => $business->id,
            'product_id' => $product->id,
            'code' => 'L001',
            'quantity' => 10,
            'initial_quantity' => 10,
            'expires_at' => now()->addDays(90)->toDateString(),
        ]);

        // Venta
        $sale = Sale::create([
            'business_id' => $business->id,
            'subtotal' => 12.5,
            'tax' => 0,
            'discount' => 0,
            'total' => 12.5,
            'amount_paid' => 12.5,
            'status' => SaleStatus::Completada,
        ]);

        // Item
        $item = SaleItem::create([
            'sale_id' => $sale->id,
            'business_id' => $business->id,
            'product_id' => $product->id,
            'product_name' => $product->name,
            'quantity' => 1,
            'unit_price' => 12.5,
            'unit_cost' => 5,
        ]);

        // Asignación de lote (trazabilidad)
        SaleItemBatch::create([
            'sale_item_id' => $item->id,
            'sale_id' => $sale->id,
            'batch_id' => $batch->id,
            'quantity' => 1,
            'unit_cost' => 5,
        ]);

        // Construir payload similar al controller y renderizar la vista directamente
        $batchesMap = SaleItemBatch::with('batch')
            ->where('sale_id', $sale->id)
            ->get()
            ->groupBy('sale_item_id')
            ->map(fn ($rows) => $rows->map(function ($alloc) {
                $batch = $alloc->batch;

                return [
                    'code' => $batch?->code,
                    'expires_at' => $batch?->expires_at ? Carbon::parse($batch->expires_at)->toDateString() : null,
                    'quantity' => $alloc->quantity,
                ];
            })->values()->all())
            ->toArray();

        $payload = [
            'business' => $business,
            'sale' => $sale,
            'items' => $sale->items,
            'batchesMap' => $batchesMap,
        ];

        $view = $this->view('pdf.receipt', $payload);

        $view->assertSee('Lote: L001');
        $view->assertSee(now()->addDays(90)->format('d/m/Y'));
    }
}
