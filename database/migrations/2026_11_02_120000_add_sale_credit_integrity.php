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
            throw new RuntimeException('F2 requiere MySQL y una aplicación de DDL autorizada.');
        }

        Schema::table('sales', function (Blueprint $table): void {
            $table->unsignedSmallInteger('credit_version')->nullable();
            $table->timestamp('credit_effects_at')->nullable();
            $table->unique(['business_id', 'id'], 'sales_business_identity_unique');
        });

        Schema::table('receivables', function (Blueprint $table): void {
            $table->uuid('sale_id')->nullable();
            $table->decimal('original_amount', 14, 2)->nullable();
            $table->decimal('amount', 14, 2)->change();
            $table->decimal('paid_amount', 14, 2)->default(0)->change();
            $table->decimal('balance', 14, 2)->default(0)->change();
            $table->unique(['business_id', 'sale_id'], 'receivables_business_sale_unique');
            $table->foreign(['business_id', 'sale_id'], 'receivables_sale_business_foreign')
                ->references(['business_id', 'id'])->on('sales')->restrictOnDelete()->restrictOnUpdate();
        });

        $table = DB::connection()->getTablePrefix().'receivables';
        DB::statement("ALTER TABLE `{$table}` ADD CONSTRAINT `{$table}_source_check` CHECK (
            (sale_id IS NULL AND original_amount IS NULL) OR
            (sale_id IS NOT NULL AND original_amount IS NOT NULL AND original_amount >= 0)
        )");

        Schema::create('sale_checkout_requests', function (Blueprint $table): void {
            $table->engine = 'InnoDB';
            $table->uuid('id')->primary();
            $table->uuid('business_id');
            $table->string('request_key', 100)->charset('ascii')->collation('ascii_bin');
            $table->char('payload_hash', 64)->charset('ascii')->collation('ascii_bin');
            $table->uuid('sale_id')->nullable();
            $table->timestamps();
            $table->unique(['business_id', 'request_key'], 'sale_checkout_business_key_unique');
            $table->foreign('business_id')->references('id')->on('businesses')->restrictOnDelete();
            $table->foreign(['business_id', 'sale_id'], 'sale_checkout_sale_business_foreign')
                ->references(['business_id', 'id'])->on('sales')->restrictOnDelete()->restrictOnUpdate();
        });
    }

    public function down(): void
    {
        throw new RuntimeException('F2 requiere un plan de reversión que preserve solicitudes y deudas.');
    }
};
