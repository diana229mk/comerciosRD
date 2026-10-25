<?php

namespace Tests\Feature;

use App\Enums\BusinessType;
use App\Enums\SubscriptionPlan;
use App\Models\Business;
use App\Models\User;
use App\Models\PlatformSetting;
use App\Services\BillingCatalog;
use App\Services\SeatLimitService;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\Cache;
use Tests\TestCase;

class SeatLimitTest extends TestCase
{
    use DatabaseTransactions;

    protected function setUp(): void
    {
        parent::setUp();
        try {
            PlatformSetting::query()->where('key', BillingCatalog::SETTING_KEY)->delete();
            Cache::forget(BillingCatalog::CACHE_KEY);
        } catch (\Throwable) {
        }
    }

    protected function makeBusiness(SubscriptionPlan $plan, int $extra = 0): array
    {
        $owner = User::factory()->create();
        $business = Business::create([
            'name' => 'Plan '.$plan->value,
            'type' => BusinessType::Colmado,
            'owner_id' => $owner->id,
            'is_active' => true,
            'subscription_plan' => $plan,
            'extra_seats' => $extra,
        ]);
        $owner->businesses()->attach($business->id, ['role' => 'owner', 'is_active' => true]);

        return [$owner, $business];
    }

    public function test_esencial_allows_one_extra_user(): void
    {
        [, $business] = $this->makeBusiness(SubscriptionPlan::Esencial);
        $seats = app(SeatLimitService::class);

        $this->assertSame(2, $seats->maxSeats($business));
        $this->assertSame(700, $seats->monthlyTotal($business));
        $this->assertTrue($seats->canAdd($business));

        $extra = User::factory()->create();
        $extra->businesses()->attach($business->id, ['role' => 'cashier', 'is_active' => true]);

        $this->assertFalse($seats->canAdd($business->fresh()));
        $this->assertSame(2, $seats->activeSeats($business));
    }

    public function test_equipo_and_completo_prices_and_seats(): void
    {
        [, $equipo] = $this->makeBusiness(SubscriptionPlan::Equipo);
        [, $completo] = $this->makeBusiness(SubscriptionPlan::Completo);
        $seats = app(SeatLimitService::class);

        $this->assertSame(3, $seats->maxSeats($equipo));
        $this->assertSame(1000, $seats->monthlyTotal($equipo));
        $this->assertSame(4, $seats->maxSeats($completo));
        $this->assertSame(1300, $seats->monthlyTotal($completo));
    }

    public function test_extra_seats_add_300_each(): void
    {
        [, $business] = $this->makeBusiness(SubscriptionPlan::Completo, 2);
        $seats = app(SeatLimitService::class);

        $this->assertSame(6, $seats->maxSeats($business));
        $this->assertSame(1900, $seats->monthlyTotal($business));
    }

    public function test_inactive_pivot_does_not_consume_seat(): void
    {
        [, $business] = $this->makeBusiness(SubscriptionPlan::Esencial);
        $idle = User::factory()->create();
        $idle->businesses()->attach($business->id, ['role' => 'cashier', 'is_active' => false]);

        $seats = app(SeatLimitService::class);
        $this->assertSame(1, $seats->activeSeats($business));
        $this->assertTrue($seats->canAdd($business));
    }
}
