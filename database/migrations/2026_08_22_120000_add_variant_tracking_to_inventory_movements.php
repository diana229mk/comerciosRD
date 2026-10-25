<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('inventory_movements')) {
            return;
        }

        Schema::table('inventory_movements', function (Blueprint $table) {
            if (! Schema::hasColumn('inventory_movements', 'variant_id')) {
                $table->foreignUuid('variant_id')
                    ->nullable()
                    ->after('product_id')
                    ->constrained('product_variants')
                    ->nullOnDelete();

                $table->index(['business_id', 'variant_id'], 'im_biz_variant_idx');
            }
        });
    }

    public function down(): void
    {
        if (! Schema::hasTable('inventory_movements') || ! Schema::hasColumn('inventory_movements', 'variant_id')) {
            return;
        }

        Schema::table('inventory_movements', function (Blueprint $table) {
            $table->dropIndex('im_biz_variant_idx');
            $table->dropForeign(['variant_id']);
            $table->dropColumn('variant_id');
        });
    }
};
