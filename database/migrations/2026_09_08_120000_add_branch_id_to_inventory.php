<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * FASE S3a — Etiquetar lotes, movimientos y conteos con branch_id.
 *
 * REGLAS:
 *  - branch_id SIEMPRE nullable → registros históricos siguen sin sucursal.
 *  - ON DELETE SET NULL.
 *  - NO se cambia la lógica de POS ni de deducción por FEFO en esta fase.
 *    Cada lote guarda la sucursal donde entró; el POS descuenta como antes
 *    (sale de cualquier lote disponible del negocio). El filtro efectivo
 *    por sucursal en la venta se difiere a S3a+ / S3c (opt-in por negocio).
 *  - NO hay backfill retroactivo.
 *
 * TABLAS AFECTADAS:
 *  - product_batches
 *  - inventory_movements
 *  - stock_counts
 *  - stock_count_items
 */
return new class extends Migration
{
    /** @var list<string> */
    private array $tables = [
        'product_batches',
        'inventory_movements',
        'stock_counts',
        'stock_count_items',
    ];

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
