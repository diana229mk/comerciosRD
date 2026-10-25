<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('ecf_settings')) {
            return;
        }

        Schema::create('ecf_settings', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('business_id')
                ->unique()
                ->constrained('businesses')
                ->cascadeOnDelete();

            // test | cert | production
            $table->string('environment', 20)->default('test');

            // Certificado digital (.p12 / .pfx) — ruta en disco
            $table->string('certificate_path')->nullable();
            // Contraseña del certificado (cifrada)
            $table->text('certificate_password')->nullable();

            // Credenciales API DGII (cuando se implemente el envío)
            $table->string('api_username')->nullable();
            $table->text('api_password')->nullable();

            // Preferencias de emisión
            $table->string('default_ecf_type', 2)->default('32');
            $table->boolean('auto_issue_on_sale')->default(false);
            $table->boolean('require_buyer_rnc_for_31')->default(true);

            // Contacto técnico / notas internas
            $table->string('contact_email')->nullable();
            $table->text('notes')->nullable();

            $table->boolean('is_ready')->default(false); // checklist básico OK
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('ecf_settings');
    }
};
