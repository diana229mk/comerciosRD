<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('ecf_sequences')) {
            return;
        }

        Schema::create('ecf_sequences', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('business_id')->constrained('businesses')->cascadeOnDelete();

            // Tipo de comprobante DGII (31, 32, 33, 34, …)
            $table->string('ecf_type', 2);

            // Rango autorizado por la DGII
            $table->string('series', 10)->nullable();
            $table->unsignedBigInteger('from_number');
            $table->unsignedBigInteger('to_number');
            $table->unsignedBigInteger('current_number')->default(0);

            $table->date('valid_from')->nullable();
            $table->date('valid_until')->nullable();

            $table->boolean('is_active')->default(true);
            $table->timestamps();

            $table->unique(['business_id', 'ecf_type', 'from_number']);
            $table->index(['business_id', 'ecf_type', 'is_active']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('ecf_sequences');
    }
};
