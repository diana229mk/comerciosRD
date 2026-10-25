<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Sucursales (branches) de un negocio.
 *
 * FASE S1 — Esqueleto seguro:
 *  - Se crea la tabla, pero NINGUNA tabla operativa (sales, orders,
 *    cash_sessions, product_stock, expenses) recibe branch_id todavía.
 *  - user_business.branch_id es nullable → todo el código legacy sigue
 *    funcionando idéntico. Un negocio sin sucursales creadas se comporta
 *    igual que antes.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('branches', function (Blueprint $table) {
            $table->uuid('id')->primary();

            $table->foreignUuid('business_id')
                ->constrained('businesses')
                ->cascadeOnDelete();

            $table->string('name', 120);
            $table->string('code', 20)->nullable();
            $table->string('address', 255)->nullable();
            $table->string('phone', 30)->nullable();

            $table->boolean('is_main')->default(false);
            $table->boolean('is_active')->default(true);

            $table->text('notes')->nullable();

            $table->timestamps();
            $table->softDeletes();

            $table->index(['business_id', 'is_active']);
            $table->index(['business_id', 'is_main']);
            // Código único por negocio (permite null en varias filas).
            $table->unique(['business_id', 'code']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('branches');
    }
};
