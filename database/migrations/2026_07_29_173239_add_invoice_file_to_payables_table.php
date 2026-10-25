<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('payables', function (Blueprint $table) {
            // paid_amount ya existe en la tabla
            if (! Schema::hasColumn('payables', 'invoice_file')) {
                $table->string('invoice_file')->nullable()->after('notes');
            }
        });
    }

    public function down(): void
    {
        Schema::table('payables', function (Blueprint $table) {
            if (Schema::hasColumn('payables', 'invoice_file')) {
                $table->dropColumn('invoice_file');
            }
        });
    }
};
