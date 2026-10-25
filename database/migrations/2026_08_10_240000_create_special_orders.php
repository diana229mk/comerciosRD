<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('special_orders', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('business_id')->constrained()->cascadeOnDelete();
            $table->string('code')->index();
            $table->foreignUuid('customer_id')->nullable()->constrained();
            $table->foreignUuid('supplier_id')->nullable()->constrained();
            $table->string('status')->default('solicitado');
            // solicitado, cotizado, ordenado, recibido, entregado, cancelado
            $table->decimal('deposit_amount', 15, 2)->default(0);
            $table->date('expected_date')->nullable();
            $table->foreignUuid('purchase_id')->nullable();
            $table->foreignUuid('sale_id')->nullable();
            $table->foreignUuid('created_by')->nullable()->constrained('users');
            $table->text('notes')->nullable();
            $table->timestamps();
            $table->unique(['business_id', 'code']);
        });

        Schema::create('special_order_items', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('business_id')->constrained()->cascadeOnDelete();
            $table->foreignUuid('special_order_id')->constrained()->cascadeOnDelete();
            $table->foreignUuid('product_id')->nullable()->constrained(); // puede ser null si aún no existe
            $table->string('description');
            $table->decimal('quantity', 15, 3);
            $table->decimal('quoted_cost', 15, 2)->nullable();
            $table->decimal('quoted_price', 15, 2)->nullable();
            $table->string('status')->default('pendiente');
            $table->text('notes')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('special_order_items');
        Schema::dropIfExists('special_orders');
    }
};
