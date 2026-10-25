<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('receivable_payments')) {
            return;
        }

        Schema::create('receivable_payments', function (Blueprint $table) {
            $table->uuid('id')->primary();

            $table->foreignUuid('receivable_id')
                ->constrained('receivables')
                ->cascadeOnDelete();

            $table->foreignUuid('business_id')
                ->constrained('businesses')
                ->cascadeOnDelete();

            $table->decimal('amount', 12, 2);
            $table->string('payment_method', 40)->default('efectivo');
            $table->date('payment_date');
            $table->string('reference')->nullable();
            $table->text('notes')->nullable();

            $table->foreignUuid('created_by')
                ->nullable()
                ->constrained('users')
                ->nullOnDelete();

            $table->timestamps();

            $table->index(['business_id', 'payment_date']);
            $table->index(['receivable_id', 'payment_date']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('receivable_payments');
    }
};
