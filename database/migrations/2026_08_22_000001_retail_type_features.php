<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('product_seasons', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('business_id')->constrained()->cascadeOnDelete();
            $table->string('name');
            $table->string('code')->index();
            $table->string('type')->default('season');
            $table->date('start_date')->nullable();
            $table->date('end_date')->nullable();
            $table->boolean('is_active')->default(true);
            $table->timestamps();
            $table->unique(['business_id', 'code']);
        });

        Schema::table('products', function (Blueprint $table) {
            $table->foreignUuid('season_id')->nullable()->after('category_id')->constrained('product_seasons')->nullOnDelete();
            $table->string('collection')->nullable()->after('season_id');
            $table->string('style')->nullable()->after('collection');
            $table->boolean('is_liquidation')->default(false)->after('style');
        });

        Schema::table('customers', function (Blueprint $table) {
            $table->string('preferred_size')->nullable()->after('segment');
            $table->string('preferred_color')->nullable()->after('preferred_size');
            $table->date('birth_date')->nullable()->after('preferred_color');
            $table->string('style_profile')->nullable()->after('birth_date');
        });

        Schema::create('customer_loyalty_accounts', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('business_id')->constrained()->cascadeOnDelete();
            $table->foreignUuid('customer_id')->constrained()->cascadeOnDelete();
            $table->integer('points')->default(0);
            $table->decimal('wallet_balance', 12, 2)->default(0);
            $table->string('tier')->default('basic');
            $table->string('status')->default('active');
            $table->timestamp('last_points_date')->nullable();
            $table->timestamps();
            $table->unique(['business_id', 'customer_id']);
        });

        Schema::create('gift_cards', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('business_id')->constrained()->cascadeOnDelete();
            $table->string('code')->unique();
            $table->foreignUuid('customer_id')->nullable()->constrained()->nullOnDelete();
            $table->decimal('amount', 12, 2)->default(0);
            $table->decimal('balance', 12, 2)->default(0);
            $table->string('status')->default('active');
            $table->date('expires_at')->nullable();
            $table->text('notes')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('gift_cards');
        Schema::dropIfExists('customer_loyalty_accounts');
        Schema::table('customers', function (Blueprint $table) {
            $table->dropColumn(['preferred_size', 'preferred_color', 'birth_date', 'style_profile']);
        });
        Schema::table('products', function (Blueprint $table) {
            $table->dropConstrainedForeignId('season_id');
            $table->dropColumn(['collection', 'style', 'is_liquidation']);
        });
        Schema::dropIfExists('product_seasons');
    }
};
