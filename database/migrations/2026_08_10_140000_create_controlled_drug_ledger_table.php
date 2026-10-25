<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Libro de registro de medicamentos controlados (DIGEMAPS).
 *
 * Registra cada entrada / salida / devolución / ajuste de todo producto
 * con products.controlled_type != null. Mantiene saldo running por producto
 * para auditoría y reporte oficial.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('controlled_drug_ledger')) {
            return;
        }

        Schema::create('controlled_drug_ledger', function (Blueprint $table) {
            $table->uuid('id')->primary();

            $table->foreignUuid('business_id')
                ->constrained('businesses')
                ->cascadeOnDelete();

            $table->foreignUuid('product_id')
                ->constrained('products')
                ->cascadeOnDelete();

            // Copia del tipo controlado al momento del asiento (anexo_i / ii / iii / psicotropico)
            $table->string('controlled_type', 30);

            // entrada | salida | devolucion_entrada | devolucion_salida | ajuste_entrada | ajuste_salida | anulacion
            $table->string('movement_type', 30);

            $table->decimal('quantity', 12, 3);
            $table->decimal('balance_after', 12, 3);

            $table->foreignUuid('batch_id')
                ->nullable()
                ->constrained('product_batches')
                ->nullOnDelete();

            // Referencia polimórfica (Purchase, Sale, InventoryMovement)
            $table->uuid('reference_id')->nullable();
            $table->string('reference_type')->nullable();

            // Datos de receta / paciente / médico (para salidas)
            $table->foreignUuid('prescription_id')
                ->nullable()
                ->constrained('prescriptions')
                ->nullOnDelete();

            $table->string('patient_name')->nullable();
            $table->string('patient_document', 30)->nullable();
            $table->string('doctor_name')->nullable();
            $table->string('doctor_exequatur', 60)->nullable();

            // Origen/documento externo (nº compra, nº venta)
            $table->string('document_number', 60)->nullable();

            $table->text('notes')->nullable();

            $table->foreignUuid('created_by')
                ->nullable()
                ->constrained('users')
                ->nullOnDelete();

            $table->timestamp('recorded_at');
            $table->timestamps();

            $table->index(['business_id', 'product_id', 'recorded_at'], 'cdl_biz_prod_date_idx');
            $table->index(['business_id', 'controlled_type', 'recorded_at'], 'cdl_biz_ctype_date_idx');
            $table->index(['business_id', 'movement_type'], 'cdl_biz_mtype_idx');
            $table->index(['reference_type', 'reference_id'], 'cdl_ref_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('controlled_drug_ledger');
    }
};
