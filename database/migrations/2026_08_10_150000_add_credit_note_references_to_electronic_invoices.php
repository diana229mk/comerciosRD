<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Nota de Crédito Electrónica (e-CF tipo 34).
 * DGII exige que las NC referencien el e-CF original y un código de razón:
 *   1 = Anulación
 *   2 = Corrección de información
 *   3 = Devolución de mercancía
 *   4 = Descuentos
 * Fuente: Norma General 06-2018 y Anexo A del formato e-CF.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('electronic_invoices')) {
            return;
        }

        Schema::table('electronic_invoices', function (Blueprint $table) {
            if (! Schema::hasColumn('electronic_invoices', 'references_invoice_id')) {
                $table->foreignUuid('references_invoice_id')
                    ->nullable()
                    ->after('sale_id')
                    ->constrained('electronic_invoices')
                    ->nullOnDelete();
            }
            if (! Schema::hasColumn('electronic_invoices', 'references_ecf_number')) {
                $table->string('references_ecf_number', 30)->nullable()->after('references_invoice_id');
            }
            if (! Schema::hasColumn('electronic_invoices', 'modification_code')) {
                // 1..4 según DGII (obligatorio en NC/ND)
                $table->unsignedTinyInteger('modification_code')->nullable()->after('references_ecf_number');
            }
            if (! Schema::hasColumn('electronic_invoices', 'modification_reason')) {
                $table->string('modification_reason', 250)->nullable()->after('modification_code');
            }
        });
    }

    public function down(): void
    {
        Schema::table('electronic_invoices', function (Blueprint $table) {
            if (Schema::hasColumn('electronic_invoices', 'modification_reason')) {
                $table->dropColumn('modification_reason');
            }
            if (Schema::hasColumn('electronic_invoices', 'modification_code')) {
                $table->dropColumn('modification_code');
            }
            if (Schema::hasColumn('electronic_invoices', 'references_ecf_number')) {
                $table->dropColumn('references_ecf_number');
            }
            if (Schema::hasColumn('electronic_invoices', 'references_invoice_id')) {
                $table->dropForeign(['references_invoice_id']);
                $table->dropColumn('references_invoice_id');
            }
        });
    }
};
