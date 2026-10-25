<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('products', function (Blueprint $table) {
            $table->uuid('id')->primary();

            $table->foreignUuid('business_id')
                ->constrained('businesses')
                ->cascadeOnDelete();

            $table->string('name');
            $table->string('sku', 64)->nullable();
            $table->string('barcode', 64)->nullable();
            $table->text('description')->nullable();

            // Precios en DOP (2 decimales)
            $table->decimal('price', 12, 2)->default(0);
            $table->decimal('cost', 12, 2)->default(0);

            // Inventario
            $table->decimal('stock', 14, 3)->default(0);
            $table->decimal('min_stock', 14, 3)->default(0);
            $table->string('unit', 30)->default('unidad'); // unidad|caja|libra|galon|saco|docena
            $table->boolean('track_stock')->default(true);

            $table->boolean('is_active')->default(true);
            $table->string('image')->nullable();

            $table->timestamps();
            $table->softDeletes();

            // SKU único por negocio (no global)
            $table->unique(['business_id', 'sku']);
            $table->index(['business_id', 'is_active']);
            $table->index(['business_id', 'barcode']);
            $table->index(['business_id', 'name']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('products');
    }
};
