<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Evoluciona sales/sale_items hacia esquema POS profesional
 * sin perder datos existentes.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('sales', function (Blueprint $table) {
            if (! Schema::hasColumn('sales', 'sale_number')) {
                $table->string('sale_number', 40)->nullable()->after('business_id');
            }
            if (! Schema::hasColumn('sales', 'customer_name')) {
                $table->string('customer_name')->nullable()->after('customer_id');
            }
            if (! Schema::hasColumn('sales', 'created_by')) {
                $table->foreignUuid('created_by')
                    ->nullable()
                    ->after('notes')
                    ->constrained('users')
                    ->nullOnDelete();
            }
            if (! Schema::hasColumn('sales', 'change')) {
                $table->decimal('change', 12, 2)->default(0)->after('amount_paid');
            }
            if (! Schema::hasColumn('sales', 'sale_date')) {
                $table->timestamp('sale_date')->nullable()->after('created_by');
            }
        });

        // Copiar datos legacy
        foreach (DB::table('sales')->orderBy('created_at')->get() as $sale) {
            DB::table('sales')->where('id', $sale->id)->update([
                'sale_number' => $sale->sale_number ?: $sale->number,
                'created_by' => $sale->created_by ?: $sale->user_id,
                'change' => $sale->change ?? $sale->change_due ?? 0,
                'sale_date' => $sale->sale_date ?: $sale->sold_at,
                'status' => match ($sale->status) {
                    'completed' => 'completada',
                    'cancelled' => 'anulada',
                    'refunded' => 'anulada',
                    default => $sale->status ?: 'completada',
                },
                'payment_method' => match ($sale->payment_method) {
                    'cash' => 'efectivo',
                    'card' => 'tarjeta',
                    'transfer' => 'transferencia',
                    'mixed' => 'mixto',
                    'credit' => 'credito',
                    default => $sale->payment_method ?: 'efectivo',
                },
            ]);
        }

        // Unique sale_number por negocio (si aún no existe)
        Schema::table('sales', function (Blueprint $table) {
            // Hacer sale_number no null donde se pueda
            try {
                $table->unique(['business_id', 'sale_number'], 'sales_business_sale_number_unique');
            } catch (\Throwable) {
                // ya existe
            }
            try {
                $table->index(['business_id', 'sale_date'], 'sales_business_sale_date_index');
            } catch (\Throwable) {
            }
        });

        Schema::table('sale_items', function (Blueprint $table) {
            if (! Schema::hasColumn('sale_items', 'subtotal')) {
                $table->decimal('subtotal', 12, 2)->default(0)->after('quantity');
            }
        });

        foreach (DB::table('sale_items')->orderBy('id')->get() as $item) {
            DB::table('sale_items')->where('id', $item->id)->update([
                'subtotal' => $item->subtotal ?? $item->line_total ?? 0,
            ]);
        }
    }

    public function down(): void
    {
        Schema::table('sale_items', function (Blueprint $table) {
            if (Schema::hasColumn('sale_items', 'subtotal')) {
                $table->dropColumn('subtotal');
            }
        });

        Schema::table('sales', function (Blueprint $table) {
            if (Schema::hasColumn('sales', 'sale_number')) {
                $table->dropColumn('sale_number');
            }
            if (Schema::hasColumn('sales', 'customer_name')) {
                $table->dropColumn('customer_name');
            }
            if (Schema::hasColumn('sales', 'created_by')) {
                $table->dropConstrainedForeignId('created_by');
            }
            if (Schema::hasColumn('sales', 'change')) {
                $table->dropColumn('change');
            }
            if (Schema::hasColumn('sales', 'sale_date')) {
                $table->dropColumn('sale_date');
            }
        });
    }
};
