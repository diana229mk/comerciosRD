<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Alinea purchases / purchase_items al esquema del módulo de Compras.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('purchases')) {
            Schema::table('purchases', function (Blueprint $table) {
                if (! Schema::hasColumn('purchases', 'supplier_name')) {
                    $table->string('supplier_name')->nullable()->after('supplier_id');
                }
                if (! Schema::hasColumn('purchases', 'invoice_number')) {
                    $table->string('invoice_number')->nullable()->after('purchase_number');
                }
                if (! Schema::hasColumn('purchases', 'due_date')) {
                    $table->date('due_date')->nullable()->after('purchase_date');
                }
            });

            // Migrar datos de columnas antiguas
            if (Schema::hasColumn('purchases', 'invoice_reference')) {
                DB::statement('UPDATE purchases SET invoice_number = COALESCE(invoice_number, invoice_reference) WHERE invoice_reference IS NOT NULL');
            }

            DB::table('purchases')->where('status', 'cancelada')->update(['status' => 'anulada']);
            DB::table('purchases')->where('payment_status', 'credito')->update(['payment_status' => 'pendiente']);

            // Rellenar supplier_name desde relación
            DB::statement('
                UPDATE purchases p
                INNER JOIN suppliers s ON s.id = p.supplier_id
                SET p.supplier_name = s.name
                WHERE p.supplier_name IS NULL AND p.supplier_id IS NOT NULL
            ');
        }

        if (Schema::hasTable('purchase_items')) {
            // quantity: mantener decimal si hay decimales; no forzar integer destructivo
            // product_id nullable se deja por compatibilidad
        }
    }

    public function down(): void
    {
        if (! Schema::hasTable('purchases')) {
            return;
        }

        Schema::table('purchases', function (Blueprint $table) {
            if (Schema::hasColumn('purchases', 'due_date')) {
                $table->dropColumn('due_date');
            }
            if (Schema::hasColumn('purchases', 'invoice_number')) {
                $table->dropColumn('invoice_number');
            }
            if (Schema::hasColumn('purchases', 'supplier_name')) {
                $table->dropColumn('supplier_name');
            }
        });

        DB::table('purchases')->where('status', 'anulada')->update(['status' => 'cancelada']);
        DB::table('purchases')->where('payment_status', 'pendiente')->update(['payment_status' => 'credito']);
    }
};
