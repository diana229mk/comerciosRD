<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (DB::getDriverName() !== 'mysql') {
            throw new RuntimeException('DATA-02 requiere MySQL y una aplicación de DDL autorizada.');
        }

        foreach ([
            'businesses',
            'sales',
            'receivables',
            'receivable_payments',
            'cash_sessions',
            'inventory_movements',
            'electronic_invoices',
            'journal_entries',
        ] as $table) {
            if (! Schema::hasTable($table)) {
                throw new RuntimeException("DATA-02 requiere la tabla {$table}.");
            }
        }

        foreach ([
            'cash_session_movements',
            'receivable_payment_reversals',
            'sale_return_operations',
        ] as $table) {
            if (Schema::hasTable($table)) {
                throw new RuntimeException("DATA-02 detectó una tabla parcial preexistente: {$table}.");
            }
        }

        foreach ([
            'receivables.sale_id',
            'receivables.original_amount',
        ] as $column) {
            [$table, $name] = explode('.', $column, 2);
            if (! Schema::hasColumn($table, $name)) {
                throw new RuntimeException("DATA-02 requiere la columna {$column} creada por la integridad de ventas a crédito.");
            }
        }

        foreach ([
            'receivable_payments.cash_session_id',
            'receivable_payments.request_key',
            'receivable_payments.payload_hash',
            'inventory_movements.sale_return_operation_id',
            'journal_entries.source_type',
            'journal_entries.source_id',
        ] as $column) {
            [$table, $name] = explode('.', $column, 2);
            if (Schema::hasColumn($table, $name)) {
                throw new RuntimeException("DATA-02 detectó una columna parcial preexistente: {$column}.");
            }
        }

        $indexes = DB::table('information_schema.statistics')
            ->where('table_schema', DB::getDatabaseName())
            ->whereIn('index_name', [
                'receivables_business_identity_unique',
                'cash_sessions_business_identity_unique',
                'receivable_payments_business_identity_unique',
                'sale_return_operations_business_identity_unique',
                'electronic_invoices_business_identity_unique',
                'receivable_payments_receivable_business_foreign',
                'receivable_payments_cash_session_business_foreign',
                'receivable_payments_business_request_unique',
                'journal_entries_source_unique',
            ])
            ->exists();
        if ($indexes) {
            throw new RuntimeException('DATA-02 detectó índices o restricciones parciales preexistentes.');
        }

        $oldPaymentForeignKey = DB::table('information_schema.key_column_usage')
            ->where('constraint_schema', DB::getDatabaseName())
            ->where('table_name', 'receivable_payments')
            ->where('constraint_name', 'receivable_payments_receivable_id_foreign')
            ->exists();
        if (! $oldPaymentForeignKey) {
            throw new RuntimeException('DATA-02 no encontró la FK histórica esperada de receivable_payments.');
        }

        $inconsistentPayments = DB::table('receivable_payments as payments')
            ->leftJoin('receivables as receivables', function ($join): void {
                $join->on('receivables.id', '=', 'payments.receivable_id')
                    ->on('receivables.business_id', '=', 'payments.business_id');
            })
            ->where(function ($query): void {
                $query->whereNull('receivables.id')
                    ->orWhere('payments.amount', '<=', 0);
            })
            ->exists();
        if ($inconsistentPayments) {
            throw new RuntimeException('DATA-02 encontró pagos de CxC huérfanos, cruzados de empresa o no positivos.');
        }

        Schema::table('receivables', function (Blueprint $table): void {
            $table->unique(['business_id', 'id'], 'receivables_business_identity_unique');
        });

        Schema::table('cash_sessions', function (Blueprint $table): void {
            $table->unique(['business_id', 'id'], 'cash_sessions_business_identity_unique');
        });

        Schema::table('receivable_payments', function (Blueprint $table): void {
            $table->unique(['business_id', 'id'], 'receivable_payments_business_identity_unique');
        });

        Schema::table('electronic_invoices', function (Blueprint $table): void {
            $table->unique(['business_id', 'id'], 'electronic_invoices_business_identity_unique');
        });

        Schema::table('receivable_payments', function (Blueprint $table): void {
            $table->dropForeign('receivable_payments_receivable_id_foreign');
            $table->foreignUuid('cash_session_id')->nullable()->after('business_id');
            $table->string('request_key', 100)->nullable()->after('cash_session_id');
            $table->char('payload_hash', 64)->nullable()->after('request_key');
            $table->foreign(['business_id', 'receivable_id'], 'receivable_payments_receivable_business_foreign')
                ->references(['business_id', 'id'])->on('receivables')
                ->restrictOnDelete()->restrictOnUpdate();
            $table->foreign(['business_id', 'cash_session_id'], 'receivable_payments_cash_session_business_foreign')
                ->references(['business_id', 'id'])->on('cash_sessions')
                ->restrictOnDelete()->restrictOnUpdate();
            $table->unique(['business_id', 'request_key'], 'receivable_payments_business_request_unique');
        });

        Schema::create('cash_session_movements', function (Blueprint $table): void {
            $table->engine = 'InnoDB';
            $table->uuid('id')->primary();
            $table->uuid('business_id');
            $table->uuid('cash_session_id');
            $table->string('source_type', 80);
            $table->uuid('source_id');
            $table->string('direction', 8);
            $table->decimal('amount', 14, 2);
            $table->foreignUuid('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->unique(
                ['business_id', 'source_type', 'source_id', 'direction'],
                'cash_session_movements_source_unique',
            );
            $table->foreign('business_id')->references('id')->on('businesses')->restrictOnDelete();
            $table->foreign(['business_id', 'cash_session_id'], 'cash_session_movements_session_business_foreign')
                ->references(['business_id', 'id'])->on('cash_sessions')
                ->restrictOnDelete()->restrictOnUpdate();
            $table->index(['cash_session_id', 'direction']);
        });

        Schema::create('receivable_payment_reversals', function (Blueprint $table): void {
            $table->engine = 'InnoDB';
            $table->uuid('id')->primary();
            $table->uuid('business_id');
            $table->uuid('receivable_payment_id');
            $table->uuid('cash_session_id')->nullable();
            $table->string('request_key', 100);
            $table->char('payload_hash', 64);
            $table->text('reason');
            $table->foreignUuid('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->unique(['business_id', 'request_key'], 'receivable_payment_reversals_request_unique');
            $table->unique('receivable_payment_id', 'receivable_payment_reversals_payment_unique');
            $table->foreign('business_id')->references('id')->on('businesses')->restrictOnDelete();
            $table->foreign(['business_id', 'receivable_payment_id'], 'receivable_payment_reversals_payment_business_foreign')
                ->references(['business_id', 'id'])->on('receivable_payments')
                ->restrictOnDelete()->restrictOnUpdate();
            $table->foreign(['business_id', 'cash_session_id'], 'receivable_payment_reversals_session_business_foreign')
                ->references(['business_id', 'id'])->on('cash_sessions')
                ->restrictOnDelete()->restrictOnUpdate();
        });

        Schema::create('sale_return_operations', function (Blueprint $table): void {
            $table->engine = 'InnoDB';
            $table->uuid('id')->primary();
            $table->uuid('business_id');
            $table->uuid('sale_id');
            $table->string('request_key', 100);
            $table->char('payload_hash', 64);
            $table->json('allocation_quantities');
            $table->decimal('refund_amount', 14, 2)->default(0);
            $table->decimal('refund_due_amount', 14, 2)->default(0);
            $table->decimal('tax_amount', 14, 2)->default(0);
            $table->decimal('inventory_cost', 14, 2)->default(0);
            $table->string('refund_channel', 30)->default('none');
            $table->string('refund_method', 40)->nullable();
            $table->string('refund_status', 20)->default('none');
            $table->uuid('refund_cash_session_id')->nullable();
            $table->string('refund_settlement_key', 100)->nullable();
            $table->char('refund_settlement_hash', 64)->nullable();
            $table->timestamp('refund_settled_at')->nullable();
            $table->text('refund_note')->nullable();
            $table->boolean('ecf_requested')->default(false);
            $table->string('ecf_status', 20)->default('not_requested');
            $table->unsignedInteger('ecf_attempts')->default(0);
            $table->timestamp('ecf_available_at')->nullable();
            $table->text('ecf_last_error')->nullable();
            $table->uuid('electronic_invoice_id')->nullable();
            $table->foreignUuid('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->unique(['business_id', 'request_key'], 'sale_return_operations_request_unique');
            $table->unique(['business_id', 'id'], 'sale_return_operations_business_identity_unique');
            $table->foreign('business_id')->references('id')->on('businesses')->restrictOnDelete();
            $table->foreign(['business_id', 'sale_id'], 'sale_return_operations_sale_business_foreign')
                ->references(['business_id', 'id'])->on('sales')
                ->restrictOnDelete()->restrictOnUpdate();
            $table->foreign(['business_id', 'refund_cash_session_id'], 'sale_return_operations_session_business_foreign')
                ->references(['business_id', 'id'])->on('cash_sessions')
                ->restrictOnDelete()->restrictOnUpdate();
            $table->foreign(['business_id', 'electronic_invoice_id'], 'sale_return_operations_invoice_business_foreign')
                ->references(['business_id', 'id'])->on('electronic_invoices')
                ->restrictOnDelete()->restrictOnUpdate();
            $table->index(['ecf_status', 'ecf_available_at', 'id']);
            $table->index(['business_id', 'sale_id', 'created_at']);
        });

        Schema::table('inventory_movements', function (Blueprint $table): void {
            $table->uuid('sale_return_operation_id')->nullable()->after('reference_id');
            $table->foreign(['business_id', 'sale_return_operation_id'], 'inventory_movements_sale_return_operation_foreign')
                ->references(['business_id', 'id'])->on('sale_return_operations')
                ->restrictOnDelete()->restrictOnUpdate();
            $table->index('sale_return_operation_id', 'inventory_movements_sale_return_operation_index');
        });

        Schema::table('journal_entries', function (Blueprint $table): void {
            $table->string('source_type', 80)->nullable()->after('reference');
            $table->uuid('source_id')->nullable()->after('source_type');
            $table->unique(['business_id', 'source_type', 'source_id'], 'journal_entries_source_unique');
        });
    }

    public function down(): void
    {
        throw new RuntimeException('DATA-02 contiene historial financiero y requiere un plan de reversión aprobado.');
    }
};
