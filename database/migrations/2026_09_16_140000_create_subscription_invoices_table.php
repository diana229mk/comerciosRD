<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('subscription_invoices', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('business_id')->constrained('businesses')->cascadeOnDelete();
            $table->string('plan', 20);
            $table->unsignedSmallInteger('extra_seats')->default(0);
            $table->unsignedInteger('amount')->default(0);
            $table->date('period_start');
            $table->date('period_end');
            $table->date('due_at');
            $table->timestamp('paid_at')->nullable();
            $table->string('status', 20)->default('pending');
            $table->string('notes', 255)->nullable();
            $table->timestamps();

            $table->unique(['business_id', 'period_start'], 'sub_invoices_biz_period_uniq');
            $table->index(['status', 'due_at']);
            $table->index('plan');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('subscription_invoices');
    }
};
