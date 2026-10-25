<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Tablas de seguridad empresarial: audit log + 2FA en users.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->text('two_factor_secret')->nullable()->after('password');
            $table->text('two_factor_recovery_codes')->nullable()->after('two_factor_secret');
            $table->timestamp('two_factor_confirmed_at')->nullable()->after('two_factor_recovery_codes');
            $table->timestamp('password_changed_at')->nullable()->after('two_factor_confirmed_at');
            $table->unsignedSmallInteger('failed_login_attempts')->default(0)->after('password_changed_at');
            $table->timestamp('locked_until')->nullable()->after('failed_login_attempts');
            $table->string('last_login_ip', 45)->nullable()->after('locked_until');
            $table->timestamp('last_login_at')->nullable()->after('last_login_ip');
        });

        Schema::create('audit_logs', function (Blueprint $table) {
            $table->uuid('id')->primary();

            // Nullable: eventos globales (login fallido sin user, master panel)
            $table->foreignUuid('business_id')
                ->nullable()
                ->constrained('businesses')
                ->nullOnDelete();

            $table->foreignUuid('user_id')
                ->nullable()
                ->constrained('users')
                ->nullOnDelete();

            // login.failed | login.success | tenant.cross_access | password.changed | ...
            $table->string('event', 80)->index();
            $table->string('severity', 20)->default('info')->index(); // info|warning|critical
            $table->string('auditable_type')->nullable();
            $table->uuid('auditable_id')->nullable();
            $table->string('ip_address', 45)->nullable()->index();
            $table->text('user_agent')->nullable();
            $table->string('url', 500)->nullable();
            $table->string('method', 10)->nullable();
            $table->json('properties')->nullable();
            $table->text('description')->nullable();

            $table->timestamp('created_at')->useCurrent()->index();

            $table->index(['business_id', 'event']);
            $table->index(['user_id', 'event']);
            $table->index(['auditable_type', 'auditable_id']);
        });

        Schema::create('login_attempts', function (Blueprint $table) {
            $table->id();
            $table->string('email')->nullable()->index();
            $table->string('ip_address', 45)->index();
            $table->boolean('successful')->default(false);
            $table->text('user_agent')->nullable();
            $table->timestamp('attempted_at')->useCurrent();

            $table->index(['ip_address', 'attempted_at']);
            $table->index(['email', 'attempted_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('login_attempts');
        Schema::dropIfExists('audit_logs');

        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn([
                'two_factor_secret',
                'two_factor_recovery_codes',
                'two_factor_confirmed_at',
                'password_changed_at',
                'failed_login_attempts',
                'locked_until',
                'last_login_ip',
                'last_login_at',
            ]);
        });
    }
};
