<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('master_audit_logs', function (Blueprint $table) {
            $table->uuid('id')->primary();

            // users.id es UUID en este proyecto
            $table->foreignUuid('user_id')
                ->constrained('users')
                ->cascadeOnDelete();

            $table->string('action', 80)->index(); // suspend_user, reset_password, ...
            $table->string('model_type')->nullable();
            $table->uuid('model_id')->nullable();
            $table->json('old_values')->nullable();
            $table->json('new_values')->nullable();
            $table->string('ip_address', 45)->nullable()->index();
            $table->text('user_agent')->nullable();
            $table->string('url', 500)->nullable();
            $table->timestamp('created_at')->useCurrent()->index();

            $table->index(['model_type', 'model_id']);
            $table->index(['user_id', 'action']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('master_audit_logs');
    }
};
