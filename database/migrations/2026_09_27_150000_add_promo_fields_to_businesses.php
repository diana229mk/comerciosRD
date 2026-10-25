<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('businesses', function (Blueprint $table) {
            $table->unsignedTinyInteger('promo_percent')->default(0)->after('extra_seats');
            $table->date('promo_until')->nullable()->after('promo_percent');
            $table->string('promo_note', 120)->nullable()->after('promo_until');
        });
    }

    public function down(): void
    {
        Schema::table('businesses', function (Blueprint $table) {
            $table->dropColumn(['promo_percent', 'promo_until', 'promo_note']);
        });
    }
};
