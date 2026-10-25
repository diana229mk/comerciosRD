<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Amplía suppliers + tablas purchases / purchase_items.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('suppliers', function (Blueprint $table) {
            if (! Schema::hasColumn('suppliers', 'contact_name')) {
                $table->string('contact_name')->nullable()->after('name');
            }
            if (! Schema::hasColumn('suppliers', 'city')) {
                $table->string('city', 100)->nullable()->after('address');
            }
        });

        if (! Schema::hasTable('purchases')) {
            Schema::create('purchases', function (Blueprint $table) {
                $table->uuid('id')->primary();
                $table->foreignUuid('business_id')->constrained('businesses')->cascadeOnDelete();
                $table->foreignUuid('supplier_id')
                    ->nullable()
                    ->constrained('suppliers')
                    ->nullOnDelete();
                $table->string('purchase_number', 40);
                // borrador | recibida | cancelada
                $table->string('status', 20)->default('borrador');
                // pagada | credito | parcial
                $table->string('payment_status', 20)->default('credito');
                $table->date('purchase_date');
                $table->timestamp('received_at')->nullable();
                $table->decimal('subtotal', 12, 2)->default(0);
                $table->decimal('tax', 12, 2)->default(0);
                $table->decimal('total', 12, 2)->default(0);
                $table->decimal('amount_paid', 12, 2)->default(0);
                $table->string('invoice_reference')->nullable();
                $table->text('notes')->nullable();
                $table->foreignUuid('payable_id')
                    ->nullable()
                    ->constrained('payables')
                    ->nullOnDelete();
                $table->foreignUuid('created_by')
                    ->nullable()
                    ->constrained('users')
                    ->nullOnDelete();
                $table->timestamps();
                $table->softDeletes();

                $table->unique(['business_id', 'purchase_number']);
                $table->index(['business_id', 'status']);
                $table->index(['business_id', 'purchase_date']);
            });
        }

        if (! Schema::hasTable('purchase_items')) {
            Schema::create('purchase_items', function (Blueprint $table) {
                $table->uuid('id')->primary();
                $table->foreignUuid('business_id')->constrained('businesses')->cascadeOnDelete();
                $table->foreignUuid('purchase_id')->constrained('purchases')->cascadeOnDelete();
                $table->foreignUuid('product_id')
                    ->nullable()
                    ->constrained('products')
                    ->nullOnDelete();
                $table->string('product_name');
                $table->string('unit', 30)->nullable()->default('unidad');
                $table->decimal('quantity', 12, 3);
                $table->decimal('unit_cost', 12, 2);
                $table->decimal('subtotal', 12, 2);
                $table->timestamps();

                $table->index(['business_id', 'purchase_id']);
                $table->index(['purchase_id', 'product_id']);
            });
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('purchase_items');
        Schema::dropIfExists('purchases');

        Schema::table('suppliers', function (Blueprint $table) {
            if (Schema::hasColumn('suppliers', 'city')) {
                $table->dropColumn('city');
            }
            if (Schema::hasColumn('suppliers', 'contact_name')) {
                $table->dropColumn('contact_name');
            }
        });
    }
};
