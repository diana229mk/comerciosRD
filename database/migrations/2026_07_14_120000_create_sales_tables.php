<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('sales', function (Blueprint $table) {
            $table->uuid('id')->primary();

            $table->foreignUuid('business_id')
                ->constrained('businesses')
                ->cascadeOnDelete();

            $table->string('number', 40); // ej. V-20260714-0001

            $table->foreignUuid('customer_id')
                ->nullable()
                ->constrained('customers')
                ->nullOnDelete();

            $table->foreignUuid('user_id')
                ->nullable()
                ->constrained('users')
                ->nullOnDelete();

            $table->decimal('subtotal', 14, 2)->default(0);
            $table->decimal('discount', 14, 2)->default(0);
            $table->decimal('tax_rate', 5, 2)->default(18.00); // ITBIS %
            $table->decimal('tax', 14, 2)->default(0);
            $table->decimal('total', 14, 2)->default(0);

            $table->string('payment_method', 30)->default('cash'); // cash|card|transfer|mixed|credit
            $table->decimal('amount_paid', 14, 2)->default(0);
            $table->decimal('change_due', 14, 2)->default(0);

            $table->string('status', 20)->default('completed'); // completed|cancelled|refunded
            $table->text('notes')->nullable();
            $table->timestamp('sold_at')->useCurrent();

            $table->timestamps();
            $table->softDeletes();

            $table->unique(['business_id', 'number']);
            $table->index(['business_id', 'status']);
            $table->index(['business_id', 'sold_at']);
            $table->index(['business_id', 'customer_id']);
        });

        Schema::create('sale_items', function (Blueprint $table) {
            $table->uuid('id')->primary();

            $table->foreignUuid('business_id')
                ->constrained('businesses')
                ->cascadeOnDelete();

            $table->foreignUuid('sale_id')
                ->constrained('sales')
                ->cascadeOnDelete();

            $table->foreignUuid('product_id')
                ->nullable()
                ->constrained('products')
                ->nullOnDelete();

            // Snapshot al momento de la venta
            $table->string('product_name');
            $table->string('sku', 64)->nullable();
            $table->string('unit', 30)->default('unidad');

            $table->decimal('quantity', 14, 3);
            $table->decimal('unit_price', 12, 2);
            $table->decimal('unit_cost', 12, 2)->default(0);
            $table->decimal('line_total', 14, 2);

            $table->timestamps();

            $table->index(['business_id', 'sale_id']);
            $table->index(['business_id', 'product_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('sale_items');
        Schema::dropIfExists('sales');
    }
};
