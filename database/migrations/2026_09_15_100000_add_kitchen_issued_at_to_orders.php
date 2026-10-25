<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('orders', function (Blueprint $table) {
            if (! Schema::hasColumn('orders', 'kitchen_issued_at')) {
                $table->timestamp('kitchen_issued_at')->nullable()->after('completed_at');
            }
        });
    }

    public function down(): void
    {
        Schema::table('orders', function (Blueprint $table) {
            if (Schema::hasColumn('orders', 'kitchen_issued_at')) {
                $table->dropColumn('kitchen_issued_at');
            }
        });
    }
};
