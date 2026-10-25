<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * FASE S1 — Asociar cada asignación usuario-negocio a una sucursal opcional.
 *
 * branch_id es NULLABLE:
 *  - Usuarios existentes conservan pivot sin sucursal (comportamiento previo).
 *  - Cuando se cree la primera sucursal de un negocio, se puede empezar a
 *    asignar poco a poco.
 *
 * ON DELETE SET NULL: si borran una sucursal, el usuario no se queda
 * huérfano del negocio; sólo pierde la asignación de sucursal.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('user_business', function (Blueprint $table) {
            $table->foreignUuid('branch_id')
                ->nullable()
                ->after('business_id')
                ->constrained('branches')
                ->nullOnDelete();

            $table->index(['business_id', 'branch_id']);
        });
    }

    public function down(): void
    {
        Schema::table('user_business', function (Blueprint $table) {
            $table->dropIndex(['business_id', 'branch_id']);
            $table->dropConstrainedForeignId('branch_id');
        });
    }
};
