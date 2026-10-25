<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Evoluciona expenses: created_by + receipt (tabla ya existía).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('expenses', function (Blueprint $table) {
            if (! Schema::hasColumn('expenses', 'receipt')) {
                $table->string('receipt')->nullable()->after('payment_method');
            }

            if (! Schema::hasColumn('expenses', 'created_by')) {
                $table->foreignUuid('created_by')
                    ->nullable()
                    ->after('notes')
                    ->constrained('users')
                    ->nullOnDelete();
            }
        });

        // Copiar user_id → created_by si aplica
        if (Schema::hasColumn('expenses', 'user_id') && Schema::hasColumn('expenses', 'created_by')) {
            \DB::statement('UPDATE expenses SET created_by = user_id WHERE created_by IS NULL AND user_id IS NOT NULL');
        }
    }

    public function down(): void
    {
        Schema::table('expenses', function (Blueprint $table) {
            if (Schema::hasColumn('expenses', 'created_by')) {
                $table->dropConstrainedForeignId('created_by');
            }
            if (Schema::hasColumn('expenses', 'receipt')) {
                $table->dropColumn('receipt');
            }
        });
    }
};
