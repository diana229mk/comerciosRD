<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * En Farmacia, cada línea de compra representa un lote:
 *  - batch_code: número de lote (opcional, recomendado)
 *  - expires_at: fecha de vencimiento
 *
 * Al recibir la compra, se crea un ProductBatch por línea.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('purchase_items')) {
            return;
        }

        Schema::table('purchase_items', function (Blueprint $table) {
            if (! Schema::hasColumn('purchase_items', 'batch_code')) {
                $table->string('batch_code', 60)->nullable()->after('unit');
            }
            if (! Schema::hasColumn('purchase_items', 'expires_at')) {
                $table->date('expires_at')->nullable()->after('batch_code');
            }
            if (! Schema::hasColumn('purchase_items', 'batch_id')) {
                $table->foreignUuid('batch_id')
                    ->nullable()
                    ->after('expires_at')
                    ->constrained('product_batches')
                    ->nullOnDelete();
            }
        });
    }

    public function down(): void
    {
        if (! Schema::hasTable('purchase_items')) {
            return;
        }

        Schema::table('purchase_items', function (Blueprint $table) {
            if (Schema::hasColumn('purchase_items', 'batch_id')) {
                $table->dropForeign(['batch_id']);
                $table->dropColumn('batch_id');
            }
            if (Schema::hasColumn('purchase_items', 'expires_at')) {
                $table->dropColumn('expires_at');
            }
            if (Schema::hasColumn('purchase_items', 'batch_code')) {
                $table->dropColumn('batch_code');
            }
        });
    }
};
