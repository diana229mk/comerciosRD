<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('stock_counts', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('business_id')->constrained()->cascadeOnDelete();
            $table->string('code')->index();
            $table->string('status', 20)->default('draft')->index();
            // full, category, selection
            $table->string('scope', 20)->default('full');
            $table->json('scope_filters')->nullable();
            $table->timestamp('expected_at')->nullable(); // fecha snapshot
            $table->timestamp('counted_at')->nullable();  // fecha física de conteo
            $table->timestamp('completed_at')->nullable();
            $table->timestamp('cancelled_at')->nullable();
            $table->foreignUuid('started_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignUuid('closed_by')->nullable()->constrained('users')->nullOnDelete();
            $table->unsignedInteger('total_items')->default(0);
            $table->unsignedInteger('items_with_difference')->default(0);
            $table->decimal('total_difference_qty', 15, 3)->default(0);
            $table->decimal('total_cost_impact', 15, 2)->default(0);
            $table->text('notes')->nullable();
            $table->timestamps();
            $table->softDeletes();

            $table->unique(['business_id', 'code']);
        });

        Schema::create('stock_count_items', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('business_id')->constrained()->cascadeOnDelete();
            $table->foreignUuid('stock_count_id')->constrained('stock_counts')->cascadeOnDelete();
            $table->foreignUuid('product_id')->constrained()->cascadeOnDelete();
            $table->foreignUuid('batch_id')->nullable()->constrained('product_batches')->nullOnDelete();
            $table->decimal('expected_quantity', 15, 3)->default(0);
            $table->decimal('counted_quantity', 15, 3)->nullable();
            $table->decimal('difference', 15, 3)->default(0);
            $table->decimal('unit_cost', 15, 2)->default(0);
            $table->decimal('cost_impact', 15, 2)->default(0);
            $table->foreignUuid('adjustment_movement_id')->nullable()->constrained('inventory_movements')->nullOnDelete();
            $table->text('notes')->nullable();
            $table->timestamps();

            $table->index(['stock_count_id', 'product_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('stock_count_items');
        Schema::dropIfExists('stock_counts');
    }
};
