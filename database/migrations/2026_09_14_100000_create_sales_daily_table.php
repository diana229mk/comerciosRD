<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('sales_daily', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('business_id')->constrained('businesses')->cascadeOnDelete();
            $table->uuid('branch_id')->nullable();
            // MySQL permite varios NULL en UNIQUE; branch_key '' cubre "sin sucursal".
            $table->string('branch_key', 36)->default('');
            $table->date('report_date');
            $table->unsignedInteger('tickets')->default(0);
            $table->decimal('subtotal', 14, 2)->default(0);
            $table->decimal('discount', 14, 2)->default(0);
            $table->decimal('tax', 14, 2)->default(0);
            $table->decimal('total', 14, 2)->default(0);
            $table->decimal('cogs', 14, 2)->default(0);
            $table->timestamps();

            $table->unique(['business_id', 'branch_key', 'report_date'], 'sales_daily_biz_branch_date_uniq');
            $table->index(['business_id', 'report_date'], 'sales_daily_biz_date_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('sales_daily');
    }
};
