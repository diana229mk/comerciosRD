<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('orders', function (Blueprint $table) {
            if (! Schema::hasColumn('orders', 'quote_number')) {
                $table->string('quote_number')->nullable()->after('order_number');
            }
            if (! Schema::hasColumn('orders', 'valid_until')) {
                $table->date('valid_until')->nullable()->after('status');
            }
            if (! Schema::hasColumn('orders', 'converted_sale_id')) {
                $table->foreignUuid('converted_sale_id')->nullable()->constrained('sales')->nullOnDelete()->after('total');
            }
            if (! Schema::hasColumn('orders', 'notes')) {
                $table->text('notes')->nullable()->after('total');
            }
        });

        Schema::table('order_items', function (Blueprint $table) {
            if (! Schema::hasColumn('order_items', 'oem_code')) {
                $table->string('oem_code')->nullable()->after('product_name');
            }
        });
    }

    public function down(): void
    {
        Schema::table('order_items', function (Blueprint $table) {
            if (Schema::hasColumn('order_items', 'oem_code')) {
                $table->dropColumn('oem_code');
            }
        });

        Schema::table('orders', function (Blueprint $table) {
            if (Schema::hasColumn('orders', 'quote_number')) {
                $table->dropColumn('quote_number');
            }
            if (Schema::hasColumn('orders', 'valid_until')) {
                $table->dropColumn('valid_until');
            }
            if (Schema::hasColumn('orders', 'converted_sale_id')) {
                $table->dropConstrainedForeignId('converted_sale_id');
            }
        });
    }
};
