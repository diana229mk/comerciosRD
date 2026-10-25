<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Tablas de soporte del Dashboard (listas para Órdenes + Contabilidad).
 * Todas aisladas por business_id.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('products', function (Blueprint $table) {
            $table->date('expires_at')->nullable()->after('image');
            $table->index(['business_id', 'expires_at']);
        });

        Schema::create('expenses', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('business_id')->constrained('businesses')->cascadeOnDelete();
            $table->foreignUuid('user_id')->nullable()->constrained('users')->nullOnDelete();

            $table->string('category', 40); // rent|utilities|salaries|supplies|transport|marketing|maintenance|taxes|other
            $table->string('description');
            $table->decimal('amount', 14, 2);
            $table->string('payment_method', 30)->default('cash');
            $table->date('expense_date');
            $table->boolean('is_paid')->default(true);
            $table->text('notes')->nullable();

            $table->timestamps();
            $table->softDeletes();

            $table->index(['business_id', 'expense_date']);
            $table->index(['business_id', 'category']);
            $table->index(['business_id', 'is_paid']);
        });

        Schema::create('orders', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('business_id')->constrained('businesses')->cascadeOnDelete();
            $table->foreignUuid('customer_id')->nullable()->constrained('customers')->nullOnDelete();
            $table->foreignUuid('user_id')->nullable()->constrained('users')->nullOnDelete();

            $table->string('number', 40); // O-YYYYMMDD-0001
            // by_name = orden por nombre del cliente | by_number = por número de orden (ticket)
            $table->string('type', 20)->default('by_name');
            $table->string('customer_name')->nullable();
            $table->string('status', 20)->default('pending'); // pending|preparing|ready|completed|cancelled
            $table->decimal('subtotal', 14, 2)->default(0);
            $table->decimal('total', 14, 2)->default(0);
            $table->text('notes')->nullable();
            $table->timestamp('ready_at')->nullable();
            $table->timestamp('completed_at')->nullable();

            $table->timestamps();
            $table->softDeletes();

            $table->unique(['business_id', 'number']);
            $table->index(['business_id', 'status']);
            $table->index(['business_id', 'type', 'status']);
        });

        Schema::create('accounts_payable', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('business_id')->constrained('businesses')->cascadeOnDelete();

            $table->string('supplier_name');
            $table->string('reference', 80)->nullable();
            $table->string('description')->nullable();
            $table->decimal('amount', 14, 2);
            $table->decimal('amount_paid', 14, 2)->default(0);
            $table->date('issue_date');
            $table->date('due_date');
            $table->string('status', 20)->default('pending'); // pending|partial|paid|overdue
            $table->text('notes')->nullable();

            $table->timestamps();
            $table->softDeletes();

            $table->index(['business_id', 'due_date']);
            $table->index(['business_id', 'status']);
        });

        Schema::create('accounts_receivable', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('business_id')->constrained('businesses')->cascadeOnDelete();
            $table->foreignUuid('customer_id')->nullable()->constrained('customers')->nullOnDelete();

            $table->string('customer_name');
            $table->string('reference', 80)->nullable();
            $table->string('description')->nullable();
            $table->decimal('amount', 14, 2);
            $table->decimal('amount_paid', 14, 2)->default(0);
            $table->date('issue_date');
            $table->date('due_date')->nullable();
            $table->string('status', 20)->default('pending'); // pending|partial|paid|overdue
            $table->text('notes')->nullable();

            $table->timestamps();
            $table->softDeletes();

            $table->index(['business_id', 'status']);
            $table->index(['business_id', 'due_date']);
            $table->index(['business_id', 'customer_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('accounts_receivable');
        Schema::dropIfExists('accounts_payable');
        Schema::dropIfExists('orders');
        Schema::dropIfExists('expenses');

        Schema::table('products', function (Blueprint $table) {
            $table->dropIndex(['business_id', 'expires_at']);
            $table->dropColumn('expires_at');
        });
    }
};
