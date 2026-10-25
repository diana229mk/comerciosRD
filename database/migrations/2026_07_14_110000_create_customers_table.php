<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('customers', function (Blueprint $table) {
            $table->uuid('id')->primary();

            $table->foreignUuid('business_id')
                ->constrained('businesses')
                ->cascadeOnDelete();

            $table->string('name');
            $table->string('phone', 30)->nullable();
            $table->string('email')->nullable();
            $table->string('document', 40)->nullable(); // cédula / RNC
            $table->string('document_type', 20)->default('cedula'); // cedula|rnc|pasaporte
            $table->string('address')->nullable();
            $table->string('city', 100)->nullable();
            $table->text('notes')->nullable();

            $table->decimal('credit_limit', 12, 2)->default(0);
            $table->boolean('is_active')->default(true);

            $table->timestamps();
            $table->softDeletes();

            $table->index(['business_id', 'is_active']);
            $table->index(['business_id', 'phone']);
            $table->index(['business_id', 'name']);
            $table->index(['business_id', 'document']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('customers');
    }
};
