<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('product_categories')) {
            Schema::create('product_categories', function (Blueprint $table) {
                $table->uuid('id')->primary();

                $table->foreignUuid('business_id')
                    ->constrained('businesses')
                    ->cascadeOnDelete();

                // null = categoría raíz; no null = subcategoría
                $table->foreignUuid('parent_id')
                    ->nullable()
                    ->constrained('product_categories')
                    ->nullOnDelete();

                $table->string('name');
                $table->string('slug')->nullable();
                $table->text('description')->nullable();
                $table->string('color', 20)->nullable(); // badge color hint
                $table->unsignedSmallInteger('sort_order')->default(0);
                $table->boolean('is_active')->default(true);
                $table->timestamps();
                $table->softDeletes();

                $table->index(['business_id', 'is_active']);
                $table->index(['business_id', 'parent_id']);
                $table->unique(['business_id', 'slug']);
            });
        }

        Schema::table('products', function (Blueprint $table) {
            if (! Schema::hasColumn('products', 'category_id')) {
                $table->foreignUuid('category_id')
                    ->nullable()
                    ->after('business_id')
                    ->constrained('product_categories')
                    ->nullOnDelete();

                $table->index(['business_id', 'category_id']);
            }
        });
    }

    public function down(): void
    {
        Schema::table('products', function (Blueprint $table) {
            if (Schema::hasColumn('products', 'category_id')) {
                $table->dropConstrainedForeignId('category_id');
            }
        });

        Schema::dropIfExists('product_categories');
    }
};
