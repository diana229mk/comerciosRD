<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('products') || Schema::hasColumn('products', 'branch_id')) {
            return;
        }

        Schema::table('products', function (Blueprint $table) {
            $table->foreignUuid('branch_id')
                ->nullable()
                ->after('business_id')
                ->constrained('branches')
                ->nullOnDelete();
            $table->index(['business_id', 'branch_id'], 'products_biz_branch_idx');
        });
    }

    public function down(): void
    {
        if (! Schema::hasTable('products') || ! Schema::hasColumn('products', 'branch_id')) {
            return;
        }

        Schema::table('products', function (Blueprint $table) {
            $table->dropIndex('products_biz_branch_idx');
            $table->dropConstrainedForeignId('branch_id');
        });
    }
};
