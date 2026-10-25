<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('electronic_invoice_items')) {
            return;
        }

        Schema::create('electronic_invoice_items', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('electronic_invoice_id')
                ->constrained('electronic_invoices')
                ->cascadeOnDelete();
            $table->foreignUuid('product_id')
                ->nullable()
                ->constrained('products')
                ->nullOnDelete();

            $table->string('product_name');
            $table->string('product_code')->nullable();
            $table->decimal('quantity', 12, 3)->default(1);
            $table->decimal('unit_price', 12, 2);
            $table->decimal('discount', 12, 2)->default(0);
            $table->decimal('tax_rate', 5, 2)->default(18);
            $table->decimal('tax_amount', 12, 2)->default(0);
            $table->decimal('subtotal', 12, 2);
            $table->decimal('total', 12, 2);

            $table->timestamps();

            $table->index('electronic_invoice_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('electronic_invoice_items');
    }
};
