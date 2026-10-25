<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::table('products', function (Blueprint $t) {
            if (! Schema::hasColumn('products', 'search_aliases')) {
                $t->json('search_aliases')->nullable()->after('description');
            }
        });
        // Índice compuesto para acelerar búsqueda por marca+nombre en catálogos grandes
        Schema::table('products', function (Blueprint $t) {
            try { $t->index(['business_id', 'brand', 'name'], 'products_biz_brand_name_idx'); } catch (\Throwable) {}
            try { $t->index(['business_id', 'supplier_code'], 'products_biz_supplier_code_idx'); } catch (\Throwable) {}
        });
    }

    public function down(): void
    {
        Schema::table('products', function (Blueprint $t) {
            try { $t->dropIndex('products_biz_brand_name_idx'); } catch (\Throwable) {}
            try { $t->dropIndex('products_biz_supplier_code_idx'); } catch (\Throwable) {}
            $t->dropColumn('search_aliases');
        });
    }
};
