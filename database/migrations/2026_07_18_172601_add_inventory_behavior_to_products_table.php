<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('products', function (Blueprint $table) {
            if (! Schema::hasColumn('products', 'inventory_behavior')) {
                $table->string('inventory_behavior', 30)
                    ->default('deduct_on_sale')
                    ->after('track_stock');

                $table->index(['business_id', 'inventory_behavior']);
            }
        });
    }

    public function down(): void
    {
        Schema::table('products', function (Blueprint $table) {
            if (Schema::hasColumn('products', 'inventory_behavior')) {
                $table->dropIndex(['business_id', 'inventory_behavior']);
                $table->dropColumn('inventory_behavior');
            }
        });
    }
};
