<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('user_business', function (Blueprint $table) {
            if (! Schema::hasColumn('user_business', 'login_name')) {
                $table->string('login_name', 80)->nullable()->after('role');
            }
        });

        Schema::table('user_business', function (Blueprint $table) {
            try {
                $table->unique(['business_id', 'login_name'], 'user_business_biz_login_name_uniq');
            } catch (\Throwable) {
                // índice ya existe
            }
        });
    }

    public function down(): void
    {
        Schema::table('user_business', function (Blueprint $table) {
            try {
                $table->dropUnique('user_business_biz_login_name_uniq');
            } catch (\Throwable) {
            }
            if (Schema::hasColumn('user_business', 'login_name')) {
                $table->dropColumn('login_name');
            }
        });
    }
};
