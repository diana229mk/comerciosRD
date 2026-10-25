<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('products', function (Blueprint $table) {
            $table->decimal('reorder_point', 15, 3)->nullable()->after('min_stock');
            $table->decimal('reorder_quantity', 15, 3)->nullable()->after('reorder_point');
            $table->unsignedSmallInteger('lead_time_days')->nullable()->after('reorder_quantity');
            $table->foreignUuid('preferred_supplier_id')->nullable()->after('lead_time_days')
                ->constrained('suppliers')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('products', function (Blueprint $table) {
            $table->dropConstrainedForeignId('preferred_supplier_id');
            $table->dropColumn(['reorder_point', 'reorder_quantity', 'lead_time_days']);
        });
    }
};
