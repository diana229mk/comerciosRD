<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * FASE S2 — Persistir branch_id en operaciones.
 *
 * REGLAS:
 *  - branch_id es SIEMPRE nullable → registros históricos quedan sin sucursal.
 *  - ON DELETE SET NULL → borrar una sucursal no rompe ventas ni caja.
 *  - NO se aplica global scope; los reportes siguen viendo todo el negocio.
 *  - NO hay backfill retroactivo. Sólo lo que se cree desde ahora arrastra
 *    el branch_id de la sesión (via trait TracksBranch).
 *
 * TABLAS AFECTADAS:
 *  - sales
 *  - orders
 *  - cash_sessions
 *  - expenses
 *  - purchases
 */
return new class extends Migration
{
    /** @var list<string> */
    private array $tables = ['sales', 'orders', 'cash_sessions', 'expenses', 'purchases'];

    public function up(): void
    {
        foreach ($this->tables as $table) {
            if (! Schema::hasTable($table) || Schema::hasColumn($table, 'branch_id')) {
                continue;
            }

            Schema::table($table, function (Blueprint $t) use ($table): void {
                $t->foreignUuid('branch_id')
                    ->nullable()
                    ->after('business_id')
                    ->constrained('branches')
                    ->nullOnDelete();

                $t->index(['business_id', 'branch_id'], "{$table}_biz_branch_idx");
            });
        }
    }

    public function down(): void
    {
        foreach ($this->tables as $table) {
            if (! Schema::hasTable($table) || ! Schema::hasColumn($table, 'branch_id')) {
                continue;
            }

            Schema::table($table, function (Blueprint $t) use ($table): void {
                $t->dropIndex("{$table}_biz_branch_idx");
                $t->dropConstrainedForeignId('branch_id');
            });
        }
    }
};
