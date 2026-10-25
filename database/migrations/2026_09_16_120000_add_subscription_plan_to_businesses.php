<?php

use App\Models\Business;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('businesses', function (Blueprint $table) {
            if (! Schema::hasColumn('businesses', 'subscription_plan')) {
                $table->string('subscription_plan', 20)->default('esencial')->after('is_active');
            }
            if (! Schema::hasColumn('businesses', 'extra_seats')) {
                $table->unsignedSmallInteger('extra_seats')->default(0)->after('subscription_plan');
            }
        });

        Schema::table('businesses', function (Blueprint $table) {
            try {
                $table->index(['subscription_plan', 'is_active'], 'businesses_plan_active_idx');
            } catch (\Throwable) {
            }
        });

        // No dejar fuera a negocios que ya tienen más gente que el plan Esencial.
        if (Schema::hasTable('user_business')) {
            Business::query()->each(function (Business $business): void {
                $used = $business->users()->wherePivot('is_active', true)->count();
                $included = 2;
                $extra = max(0, $used - $included);
                if ($extra > 0 && (int) $business->extra_seats < $extra) {
                    $business->forceFill(['extra_seats' => $extra])->saveQuietly();
                }
            });
        }
    }

    public function down(): void
    {
        Schema::table('businesses', function (Blueprint $table) {
            try {
                $table->dropIndex('businesses_plan_active_idx');
            } catch (\Throwable) {
            }
            if (Schema::hasColumn('businesses', 'extra_seats')) {
                $table->dropColumn('extra_seats');
            }
            if (Schema::hasColumn('businesses', 'subscription_plan')) {
                $table->dropColumn('subscription_plan');
            }
        });
    }
};
