<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Módulo de Recetas Médicas (uso principal: farmacias).
 *
 *  - products.requires_prescription: producto exige receta para dispensar.
 *  - products.controlled_type: clasificación (anexo_i / anexo_ii / anexo_iii / psicotropico).
 *  - prescriptions: cabecera con médico + paciente + estado.
 *  - prescription_items: productos recetados con cantidades prescritas/dispensadas.
 *  - sale_items.prescription_item_id: vínculo de trazabilidad (qué línea de venta dispensó qué renglón de la receta).
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('products')) {
            Schema::table('products', function (Blueprint $table) {
                if (! Schema::hasColumn('products', 'requires_prescription')) {
                    $table->boolean('requires_prescription')->default(false)->after('is_active');
                }
                if (! Schema::hasColumn('products', 'controlled_type')) {
                    $table->string('controlled_type', 30)->nullable()->after('requires_prescription');
                }
            });
        }

        if (! Schema::hasTable('prescriptions')) {
            Schema::create('prescriptions', function (Blueprint $table) {
                $table->uuid('id')->primary();

                $table->foreignUuid('business_id')
                    ->constrained('businesses')
                    ->cascadeOnDelete();

                // Código correlativo (auto-generado)
                $table->string('code', 30);

                $table->foreignUuid('customer_id')
                    ->nullable()
                    ->constrained('customers')
                    ->nullOnDelete();

                // Datos del paciente (redundante para que sobreviva a eliminación del cliente)
                $table->string('patient_name');
                $table->string('patient_document', 30)->nullable();
                $table->unsignedSmallInteger('patient_age')->nullable();

                // Datos del médico
                $table->string('doctor_name');
                $table->string('doctor_license', 60)->nullable();
                $table->string('doctor_exequatur', 60)->nullable();
                $table->string('doctor_specialty', 80)->nullable();

                // Fecha de emisión y de vencimiento (algunas recetas tienen validez limitada)
                $table->date('issued_at');
                $table->date('valid_until')->nullable();

                $table->text('diagnosis')->nullable();
                $table->text('notes')->nullable();

                // Foto/escaneo de la receta física
                $table->string('image_path')->nullable();

                // pendiente | parcial | dispensada | vencida | anulada
                $table->string('status', 20)->default('pendiente');

                $table->foreignUuid('created_by')
                    ->nullable()
                    ->constrained('users')
                    ->nullOnDelete();

                $table->timestamps();
                $table->softDeletes();

                $table->unique(['business_id', 'code'], 'presc_biz_code_uq');
                $table->index(['business_id', 'status'], 'presc_biz_status_idx');
                $table->index(['business_id', 'customer_id'], 'presc_biz_customer_idx');
                $table->index(['business_id', 'issued_at'], 'presc_biz_issued_idx');
            });
        }

        if (! Schema::hasTable('prescription_items')) {
            Schema::create('prescription_items', function (Blueprint $table) {
                $table->uuid('id')->primary();

                $table->foreignUuid('business_id')
                    ->constrained('businesses')
                    ->cascadeOnDelete();

                $table->foreignUuid('prescription_id')
                    ->constrained('prescriptions')
                    ->cascadeOnDelete();

                $table->foreignUuid('product_id')
                    ->nullable()
                    ->constrained('products')
                    ->nullOnDelete();

                // Nombre libre por si el producto no existe en catálogo o se borra
                $table->string('product_name');

                $table->decimal('quantity_prescribed', 12, 3);
                $table->decimal('quantity_dispensed', 12, 3)->default(0);

                $table->string('dosage', 120)->nullable();      // ej. "500 mg cada 8 h"
                $table->string('duration', 60)->nullable();     // ej. "7 días"
                $table->text('instructions')->nullable();

                $table->timestamps();

                $table->index(['business_id', 'prescription_id'], 'pi_biz_presc_idx');
                $table->index(['business_id', 'product_id'], 'pi_biz_product_idx');
            });
        }

        if (Schema::hasTable('sale_items') && ! Schema::hasColumn('sale_items', 'prescription_item_id')) {
            Schema::table('sale_items', function (Blueprint $table) {
                $table->foreignUuid('prescription_item_id')
                    ->nullable()
                    ->after('product_id')
                    ->constrained('prescription_items')
                    ->nullOnDelete();

                $table->index(['business_id', 'prescription_item_id'], 'si_biz_presc_item_idx');
            });
        }
    }

    public function down(): void
    {
        if (Schema::hasTable('sale_items') && Schema::hasColumn('sale_items', 'prescription_item_id')) {
            Schema::table('sale_items', function (Blueprint $table) {
                $table->dropIndex('si_biz_presc_item_idx');
                $table->dropForeign(['prescription_item_id']);
                $table->dropColumn('prescription_item_id');
            });
        }

        Schema::dropIfExists('prescription_items');
        Schema::dropIfExists('prescriptions');

        if (Schema::hasTable('products')) {
            Schema::table('products', function (Blueprint $table) {
                if (Schema::hasColumn('products', 'controlled_type')) {
                    $table->dropColumn('controlled_type');
                }
                if (Schema::hasColumn('products', 'requires_prescription')) {
                    $table->dropColumn('requires_prescription');
                }
            });
        }
    }
};
