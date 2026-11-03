<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('accounting_outbox', function (Blueprint $table) {
            $table->engine = 'InnoDB';
            $table->uuid('id')->primary();
            $table->uuid('business_id');
            $table->uuid('sale_id');
            $table->uuid('journal_entry_id')->nullable();
            $table->string('status', 20)->default('pending');
            $table->unsignedInteger('publish_attempts')->default(0);
            $table->unsignedInteger('processing_attempts')->default(0);
            $table->unsignedInteger('manual_retries')->default(0);
            $table->timestamp('available_at');
            $table->uuid('lease_token')->nullable();
            $table->timestamp('published_at')->nullable();
            $table->timestamp('processed_at')->nullable();
            $table->text('last_error')->nullable();
            $table->timestamps();

            $table->unique(['business_id', 'sale_id']);
            $table->unique('journal_entry_id');
            $table->index(['status', 'available_at', 'id']);
        });

        Schema::create('accounting_sequences', function (Blueprint $table) {
            $table->engine = 'InnoDB';
            $table->uuid('business_id');
            $table->unsignedSmallInteger('year');
            $table->unsignedBigInteger('last_number')->default(0);
            $table->primary(['business_id', 'year']);
        });
    }

    public function down(): void
    {
        throw new RuntimeException('El outbox y la numeración requieren un plan de reversión que preserve sus datos.');
    }
};
