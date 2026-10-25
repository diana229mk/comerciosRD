<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('inventory_movements')) {
            return;
        }

        Schema::create('inventory_movements', function (Blueprint $table) {
            $table->uuid('id')->primary();

            $table->foreignUuid('business_id')
                ->constrained('businesses')
                ->cascadeOnDelete();

            $table->foreignUuid('product_id')
                ->constrained('products')
                ->cascadeOnDelete();

            // purchase | sale | adjustment_in | adjustment_out | return_in | return_out
            $table->string('type', 30);

            // Cantidad del movimiento (siempre positiva; el signo lo define el tipo)
            $table->decimal('quantity', 12, 3);

            // Stock del producto DESPUÉS de aplicar este movimiento
            $table->decimal('stock_after', 12, 3)->nullable();

            $table->decimal('unit_cost', 12, 2)->nullable();
            $table->text('reason')->nullable();

            // Referencia polimórfica (Sale, Order, etc.)
            $table->uuid('reference_id')->nullable();
            $table->string('reference_type')->nullable();

            $table->foreignUuid('created_by')
                ->nullable()
                ->constrained('users')
                ->nullOnDelete();

            $table->timestamps();

            $table->index(['business_id', 'product_id']);
            $table->index(['business_id', 'created_at']);
            $table->index(['business_id', 'type']);
            $table->index(['reference_type', 'reference_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('inventory_movements');
    }
};
