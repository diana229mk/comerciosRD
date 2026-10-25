<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('cash_sessions', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('business_id')->constrained('businesses')->cascadeOnDelete();
            $table->foreignUuid('user_id')->constrained('users')->cascadeOnDelete(); // Quién abrió la caja
            $table->foreignUuid('closed_by')->nullable()->constrained('users')->nullOnDelete(); // Quién cerró

            $table->decimal('opening_amount', 12, 2)->default(0);      // Monto con el que se abre
            $table->decimal('closing_amount', 12, 2)->nullable();      // Monto real contado al cerrar
            $table->decimal('expected_amount', 12, 2)->nullable();     // Monto que debería haber
            $table->decimal('difference', 12, 2)->nullable();          // Sobrante o faltante

            $table->decimal('total_sales_cash', 12, 2)->default(0);    // Total de ventas en efectivo
            $table->decimal('total_expenses_cash', 12, 2)->default(0); // Gastos pagados en efectivo

            $table->string('status', 20)->default('open'); // open | closed
            $table->text('opening_notes')->nullable();
            $table->text('closing_notes')->nullable();

            $table->timestamp('opened_at');
            $table->timestamp('closed_at')->nullable();
            $table->timestamps();

            $table->index(['business_id', 'status']);
            $table->index(['business_id', 'opened_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('cash_sessions');
    }
};
