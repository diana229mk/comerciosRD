<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        // Recepción parcial: cantidad recibida por línea
        Schema::table('purchase_items', function (Blueprint $t) {
            if (! Schema::hasColumn('purchase_items', 'received_quantity')) {
                $t->decimal('received_quantity', 15, 3)->default(0)->after('quantity');
            }
        });

        // Estado extendido de compra (parcial)
        Schema::table('purchases', function (Blueprint $t) {
            if (! Schema::hasColumn('purchases', 'is_partial')) {
                $t->boolean('is_partial')->default(false)->after('status');
            }
        });

        // Múltiples proveedores por producto con historial de precios
        if (! Schema::hasTable('product_suppliers')) {
            Schema::create('product_suppliers', function (Blueprint $t) {
                $t->uuid('id')->primary();
                $t->foreignUuid('business_id');
                $t->foreignUuid('product_id');
                $t->foreignUuid('supplier_id');
                $t->string('supplier_code')->nullable(); // SKU del proveedor
                $t->decimal('last_cost', 12, 2)->nullable();
                $t->decimal('last_quantity', 15, 3)->nullable();
                $t->date('last_purchase_date')->nullable();
                $t->integer('lead_time_days')->nullable();
                $t->boolean('is_preferred')->default(false);
                $t->timestamps();
                $t->unique(['product_id', 'supplier_id'], 'product_supplier_uniq');
                $t->index(['business_id', 'product_id']);
            });
        }
    }

    public function down(): void
    {
        Schema::table('purchase_items', fn (Blueprint $t) => $t->dropColumn('received_quantity'));
        Schema::table('purchases', fn (Blueprint $t) => $t->dropColumn('is_partial'));
        Schema::dropIfExists('product_suppliers');
    }
};
