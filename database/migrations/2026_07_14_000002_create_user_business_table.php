<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Pivot muchos-a-muchos: un usuario puede trabajar en varios comercios
 * (ej. dueño de un colmado y cajero en una ferretería).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('user_business', function (Blueprint $table) {
            $table->id();

            $table->foreignUuid('user_id')
                ->constrained('users')
                ->cascadeOnDelete();

            $table->foreignUuid('business_id')
                ->constrained('businesses')
                ->cascadeOnDelete();

            // owner | admin | manager | cashier | inventory | accountant | delivery
            $table->string('role', 40)->default('cashier');

            $table->boolean('is_active')->default(true);
            $table->timestamp('joined_at')->useCurrent();

            $table->timestamps();

            $table->unique(['user_id', 'business_id']);
            $table->index(['business_id', 'is_active']);
            $table->index(['user_id', 'is_active']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('user_business');
    }
};
