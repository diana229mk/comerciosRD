<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * El saldo running del libro de controlados usa `recorded_at` con ORDER BY,
 * pero varios asientos pueden crearse en el mismo segundo (venta + devolución
 * en el mismo turno). Elevamos la precisión a microsegundos.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('controlled_drug_ledger')) {
            return;
        }

        // Alteración compatible MySQL/MariaDB: DATETIME(6) → precisión microsegundos.
        DB::statement('ALTER TABLE controlled_drug_ledger MODIFY recorded_at DATETIME(6) NOT NULL');
    }

    public function down(): void
    {
        if (! Schema::hasTable('controlled_drug_ledger')) {
            return;
        }

        DB::statement('ALTER TABLE controlled_drug_ledger MODIFY recorded_at DATETIME NOT NULL');
    }
};
