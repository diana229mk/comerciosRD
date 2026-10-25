<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Trazabilidad de lote por línea de venta (Farmacia y cualquier negocio con lotes).
 *
 * Cada línea de venta (SaleItem) puede descomponerse en 1..N lotes por FEFO.
 * Esta tabla registra qué cantidad se despachó desde qué lote — permite:
 *  - Ticket que muestre lote y vencimiento por producto vendido.
 *  - Reportes de trazabilidad compra→lote→venta.
 *  - Devoluciones futuras que restauren al lote exacto.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('sale_item_batches')) {
            return;
        }

        Schema::create('sale_item_batches', function (Blueprint $table) {
            $table->uuid('id')->primary();

            $table->foreignUuid('business_id')
                ->constrained('businesses')
                ->cascadeOnDelete();

            $table->foreignUuid('sale_item_id')
                ->constrained('sale_items')
                ->cascadeOnDelete();

            // Sale_id redundante para queries rápidos por venta
            $table->foreignUuid('sale_id')
                ->constrained('sales')
                ->cascadeOnDelete();

            $table->foreignUuid('batch_id')
                ->constrained('product_batches')
                ->restrictOnDelete();

            $table->decimal('quantity', 12, 3);
            $table->decimal('unit_cost', 12, 2)->nullable();

            // Cantidad ya devuelta para futuras devoluciones parciales
            $table->decimal('returned_quantity', 12, 3)->default(0);

            $table->timestamps();

            $table->index(['business_id', 'sale_id'], 'sib_biz_sale_idx');
            $table->index(['business_id', 'batch_id'], 'sib_biz_batch_idx');
            $table->index(['business_id', 'sale_item_id'], 'sib_biz_item_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('sale_item_batches');
    }
};
