<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('sales', function (Blueprint $table) {
            $table->foreignUuid('prescription_id')->nullable()->after('customer_id')
                ->constrained('prescriptions')->nullOnDelete();
            $table->string('prescription_ref', 100)->nullable()->after('prescription_id');
        });
    }

    public function down(): void
    {
        Schema::table('sales', function (Blueprint $table) {
            $table->dropConstrainedForeignId('prescription_id');
            $table->dropColumn('prescription_ref');
        });
    }
};
