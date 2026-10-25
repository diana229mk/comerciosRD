<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Núcleo de inventario para Farmacias:
 *  - product_batches: lotes por producto con fecha de vencimiento.
 *  - inventory_movements.batch_id: traza qué lote se afectó.
 *
 * Un producto puede tener 0 o N lotes. Si tiene lotes, la venta debe
 * descontar por FEFO (First Expired First Out).
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('product_batches')) {
            Schema::create('product_batches', function (Blueprint $table) {
                $table->uuid('id')->primary();

                $table->foreignUuid('business_id')
                    ->constrained('businesses')
                    ->cascadeOnDelete();

                $table->foreignUuid('product_id')
                    ->constrained('products')
                    ->cascadeOnDelete();

                // Código de lote (opcional pero recomendado)
                $table->string('code', 60)->nullable();

                // Cantidad actual disponible en el lote
                $table->decimal('quantity', 12, 3)->default(0);

                // Cantidad recibida originalmente (auditoría)
                $table->decimal('initial_quantity', 12, 3)->default(0);

                // Costo unitario del lote
                $table->decimal('cost', 12, 2)->nullable();

                // Fecha de vencimiento (clave para FEFO)
                $table->date('expires_at')->nullable();

                // Fecha de recepción
                $table->date('received_at')->nullable();

                // Bloqueado manualmente (ej. retiro sanitario)
                $table->boolean('is_blocked')->default(false);

                $table->text('notes')->nullable();

                $table->foreignUuid('created_by')
                    ->nullable()
                    ->constrained('users')
                    ->nullOnDelete();

                $table->timestamps();
                $table->softDeletes();

                // FEFO: buscar lotes de un producto ordenados por vencimiento
                $table->index(['business_id', 'product_id', 'expires_at'], 'pb_biz_prod_exp_idx');
                $table->index(['business_id', 'expires_at'], 'pb_biz_exp_idx');
                $table->index(['business_id', 'is_blocked'], 'pb_biz_blocked_idx');
            });
        }

        if (Schema::hasTable('inventory_movements') && ! Schema::hasColumn('inventory_movements', 'batch_id')) {
            Schema::table('inventory_movements', function (Blueprint $table) {
                $table->foreignUuid('batch_id')
                    ->nullable()
                    ->after('product_id')
                    ->constrained('product_batches')
                    ->nullOnDelete();

                $table->index(['business_id', 'batch_id'], 'im_biz_batch_idx');
            });
        }
    }

    public function down(): void
    {
        if (Schema::hasTable('inventory_movements') && Schema::hasColumn('inventory_movements', 'batch_id')) {
            Schema::table('inventory_movements', function (Blueprint $table) {
                $table->dropIndex('im_biz_batch_idx');
                $table->dropForeign(['batch_id']);
                $table->dropColumn('batch_id');
            });
        }

        Schema::dropIfExists('product_batches');
    }
};
