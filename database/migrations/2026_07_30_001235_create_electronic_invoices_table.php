<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('electronic_invoices')) {
            return;
        }

        Schema::create('electronic_invoices', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('business_id')->constrained('businesses')->cascadeOnDelete();
            $table->foreignUuid('sale_id')->nullable()->constrained('sales')->nullOnDelete();
            $table->foreignUuid('customer_id')->nullable()->constrained('customers')->nullOnDelete();

            // Identificación del e-CF
            $table->string('ecf_type', 2);
            $table->string('ecf_number')->index();
            $table->unsignedBigInteger('sequence_number');

            // Emisor (snapshot)
            $table->string('issuer_rnc', 20);
            $table->string('issuer_name');

            // Comprador
            $table->string('buyer_rnc', 20)->nullable();
            $table->string('buyer_name')->nullable();
            $table->string('buyer_email')->nullable();

            // Montos
            $table->decimal('subtotal', 14, 2)->default(0);
            $table->decimal('discount', 14, 2)->default(0);
            $table->decimal('tax', 14, 2)->default(0); // ITBIS
            $table->decimal('total', 14, 2)->default(0);

            // Estado del comprobante
            $table->string('status', 20)->default('draft');
            // draft | issued | sent | accepted | rejected | cancelled

            // Respuesta DGII (futuro)
            $table->string('track_id')->nullable();
            $table->string('security_code')->nullable();
            $table->text('dgii_response')->nullable();
            $table->timestamp('sent_at')->nullable();
            $table->timestamp('accepted_at')->nullable();

            // XML y representación
            $table->longText('xml_content')->nullable();
            $table->string('pdf_path')->nullable();

            $table->date('issue_date');
            $table->text('notes')->nullable();
            $table->foreignUuid('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
            $table->softDeletes();

            $table->unique(['business_id', 'ecf_number']);
            $table->index(['business_id', 'ecf_type', 'status']);
            $table->index(['business_id', 'issue_date']);
            $table->index(['business_id', 'sale_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('electronic_invoices');
    }
};
