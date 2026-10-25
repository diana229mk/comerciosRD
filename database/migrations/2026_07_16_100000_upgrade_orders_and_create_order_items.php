<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Evoluciona orders (por nombre / por número) y crea order_items.
 * Tabla orders tenía 0 filas: se recrea con el esquema final.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::dropIfExists('order_items');
        Schema::dropIfExists('orders');

        Schema::create('orders', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('business_id')->constrained('businesses')->cascadeOnDelete();

            // ORD-20260716-0001
            $table->string('order_number', 40);
            // por_nombre | por_numero
            $table->string('order_type', 20)->default('por_numero');
            $table->string('customer_name')->nullable();
            $table->foreignUuid('customer_id')
                ->nullable()
                ->constrained('customers')
                ->nullOnDelete();

            // pendiente|en_preparacion|lista|completada|cancelada
            $table->string('status', 30)->default('pendiente');

            $table->decimal('subtotal', 12, 2)->default(0);
            $table->decimal('tax', 12, 2)->default(0); // ITBIS
            $table->decimal('total', 12, 2)->default(0);
            $table->text('notes')->nullable();

            $table->foreignUuid('created_by')
                ->nullable()
                ->constrained('users')
                ->nullOnDelete();

            $table->timestamp('ready_at')->nullable();
            $table->timestamp('completed_at')->nullable();
            $table->timestamps();
            $table->softDeletes();

            $table->unique(['business_id', 'order_number']);
            $table->index(['business_id', 'status']);
            $table->index(['business_id', 'order_type']);
            $table->index(['business_id', 'created_at']);
        });

        Schema::create('order_items', function (Blueprint $table) {
            $table->uuid('id')->primary();

            // Aislamiento multi-tenant (Global Scope)
            $table->foreignUuid('business_id')->constrained('businesses')->cascadeOnDelete();

            $table->foreignUuid('order_id')->constrained('orders')->cascadeOnDelete();
            $table->foreignUuid('product_id')
                ->nullable()
                ->constrained('products')
                ->nullOnDelete();

            $table->string('product_name'); // snapshot
            $table->string('unit', 30)->default('unidad');
            $table->decimal('unit_price', 12, 2);
            $table->decimal('quantity', 14, 3)->default(1);
            $table->decimal('subtotal', 12, 2);
            $table->timestamps();

            $table->index(['business_id', 'order_id']);
            $table->index('order_id');
            $table->index(['business_id', 'product_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('order_items');
        Schema::dropIfExists('orders');

        // Restaurar esquema anterior mínimo (dashboard)
        Schema::create('orders', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('business_id')->constrained('businesses')->cascadeOnDelete();
            $table->foreignUuid('customer_id')->nullable()->constrained('customers')->nullOnDelete();
            $table->foreignUuid('user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('number', 40);
            $table->string('type', 20)->default('by_name');
            $table->string('customer_name')->nullable();
            $table->string('status', 20)->default('pending');
            $table->decimal('subtotal', 14, 2)->default(0);
            $table->decimal('total', 14, 2)->default(0);
            $table->text('notes')->nullable();
            $table->timestamp('ready_at')->nullable();
            $table->timestamp('completed_at')->nullable();
            $table->timestamps();
            $table->softDeletes();
            $table->unique(['business_id', 'number']);
            $table->index(['business_id', 'status']);
            $table->index(['business_id', 'type', 'status']);
        });
    }
};
