<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Proveedores + Cuentas por pagar (payables).
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('suppliers')) {
            Schema::create('suppliers', function (Blueprint $table) {
                $table->uuid('id')->primary();
                $table->foreignUuid('business_id')->constrained('businesses')->cascadeOnDelete();
                $table->string('name');
                $table->string('phone', 30)->nullable();
                $table->string('email')->nullable();
                $table->string('rnc', 20)->nullable();
                $table->string('address')->nullable();
                $table->text('notes')->nullable();
                $table->boolean('is_active')->default(true);
                $table->timestamps();
                $table->softDeletes();

                $table->index(['business_id', 'is_active']);
                $table->index(['business_id', 'name']);
            });
        }

        if (! Schema::hasTable('payables')) {
            Schema::create('payables', function (Blueprint $table) {
                $table->uuid('id')->primary();
                $table->foreignUuid('business_id')->constrained('businesses')->cascadeOnDelete();
                $table->foreignUuid('supplier_id')
                    ->nullable()
                    ->constrained('suppliers')
                    ->nullOnDelete();
                $table->string('supplier_name')->nullable();
                $table->string('reference')->nullable();
                $table->text('description');
                $table->decimal('amount', 12, 2);
                $table->decimal('paid_amount', 12, 2)->default(0);
                $table->decimal('balance', 12, 2)->default(0);
                $table->date('due_date');
                // pendiente | parcial | pagada | vencida
                $table->string('status', 20)->default('pendiente');
                $table->text('notes')->nullable();
                $table->foreignUuid('created_by')
                    ->nullable()
                    ->constrained('users')
                    ->nullOnDelete();
                $table->timestamps();
                $table->softDeletes();

                $table->index(['business_id', 'status']);
                $table->index(['business_id', 'due_date']);
            });
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('payables');
        Schema::dropIfExists('suppliers');
    }
};
