<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('businesses', function (Blueprint $table) {
            $table->uuid('id')->primary();

            $table->string('name');
            $table->string('slug')->unique();
            $table->string('type', 40); // colmado|ferreteria|farmacia|cafeteria|almacen|distribuidora

            // Owner (usuario que creó / posee el negocio)
            $table->foreignUuid('owner_id')
                ->nullable()
                ->constrained('users')
                ->nullOnDelete();

            $table->string('logo')->nullable();
            $table->string('phone', 30)->nullable();
            $table->string('email')->nullable();
            $table->string('address')->nullable();
            $table->string('city', 100)->nullable();
            $table->string('province', 100)->nullable();
            $table->string('rnc', 20)->nullable()->index(); // RNC República Dominicana

            $table->boolean('is_active')->default(true)->index();
            $table->boolean('onboarding_completed')->default(false);

            // JSON flexible: monedas, impuestos ITBIS, tickets, horarios, etc.
            $table->json('settings')->nullable();
            // Feature flags por plan: inventory, efc_dgii, online_store, kds, ...
            $table->json('features')->nullable();

            $table->timestamp('trial_ends_at')->nullable();
            $table->timestamps();
            $table->softDeletes();

            $table->index(['type', 'is_active']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('businesses');
    }
};
