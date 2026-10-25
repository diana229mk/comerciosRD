<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('sales', function (Blueprint $table) {
            if (! Schema::hasColumn('sales', 'amount_tendered')) {
                $table->decimal('amount_tendered', 12, 2)->nullable()->after('amount_paid');
            }
            if (! Schema::hasColumn('sales', 'change_amount')) {
                $table->decimal('change_amount', 12, 2)->nullable()->after('amount_tendered');
            }
        });
    }

    public function down(): void
    {
        Schema::table('sales', function (Blueprint $table) {
            $table->dropColumn(['amount_tendered', 'change_amount']);
        });
    }
};
