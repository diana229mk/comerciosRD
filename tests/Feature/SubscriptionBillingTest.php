<?php

namespace Tests\Feature;

use App\Enums\BusinessType;
use App\Enums\SubscriptionInvoiceStatus;
use App\Enums\SubscriptionPlan;
use App\Models\Business;
use App\Models\Sale;
use App\Models\User;
use App\Services\BillingCatalog;
use App\Services\SeatLimitService;
use App\Services\SubscriptionBillingService;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Tests\TestCase;

class SubscriptionBillingTest extends TestCase
{
    use DatabaseTransactions;

    protected function setUp(): void
    {
        parent::setUp();
        try {
            \App\Models\PlatformSetting::query()->where('key', BillingCatalog::SETTING_KEY)->delete();
            \Illuminate\Support\Facades\Cache::forget(BillingCatalog::CACHE_KEY);
        } catch (\Throwable) {
        }
    }

    public function test_invoice_uses_plan_not_pos_sales(): void
    {
        $owner = User::factory()->create();
        $business = Business::create([
            'name' => 'Café Billing',
            'type' => BusinessType::Cafeteria,
            'owner_id' => $owner->id,
            'is_active' => true,
            'subscription_plan' => SubscriptionPlan::Equipo,
            'extra_seats' => 0,
        ]);
        $owner->businesses()->attach($business->id, ['role' => 'owner', 'is_active' => true]);

        $invoice = app(SubscriptionBillingService::class)->ensureCurrentInvoice($business);

        $this->assertNotNull($invoice);
        $this->assertSame(1000, (int) $invoice->amount);
        $this->assertSame(SubscriptionPlan::Equipo, $invoice->plan);
        $this->assertTrue($invoice->isOpen());
        $this->assertSame(0, Sale::withoutBusinessScope()->where('business_id', $business->id)->count());
    }

    public function test_mark_paid_counts_as_collected(): void
    {
        $owner = User::factory()->create();
        $business = Business::create([
            'name' => 'Cobro',
            'type' => BusinessType::Colmado,
            'owner_id' => $owner->id,
            'is_active' => true,
            'subscription_plan' => SubscriptionPlan::Esencial,
        ]);
        $billing = app(SubscriptionBillingService::class);
        $before = $billing->collectedThisMonth();
        $invoice = $billing->ensureCurrentInvoice($business);
        $billing->markPaid($invoice);

        $this->assertSame($before + 700, $billing->collectedThisMonth());
        $this->assertSame(SubscriptionInvoiceStatus::Paid, $invoice->fresh()->status);
    }

    public function test_master_stats_class_does_not_query_sales(): void
    {
        $src = file_get_contents(app_path('Filament/Master/Widgets/MasterStatsOverview.php'));
        $this->assertStringNotContainsString('Sale::', $src);
        $this->assertStringNotContainsString('Order::', $src);
        $this->assertStringContainsString('SubscriptionBillingService', $src);
    }

    public function test_master_home_is_subscription_manager(): void
    {
        $dash = file_get_contents(app_path('Filament/Master/Pages/Dashboard.php'));
        $this->assertStringContainsString('Manejador de suscripciones', $dash);
        $this->assertStringContainsString('SubscriptionManagerTable', $dash);
        $this->assertStringNotContainsString('Sale::', $dash);
    }

    public function test_master_can_raise_plan_prices_without_changing_paid_invoices(): void
    {
        $owner = User::factory()->create();
        $business = Business::create([
            'name' => 'Local Precios',
            'type' => BusinessType::Colmado,
            'owner_id' => $owner->id,
            'is_active' => true,
            'subscription_plan' => SubscriptionPlan::Esencial,
        ]);
        $billing = app(SubscriptionBillingService::class);
        $invoice = $billing->ensureCurrentInvoice($business);
        $this->assertSame(700, (int) $invoice->amount);
        $billing->markPaid($invoice);

        $catalog = app(BillingCatalog::class);
        $catalog->save([
            'esencial' => 900,
            'equipo' => 1200,
            'completo' => 1600,
            'extra_seat' => 400,
        ]);

        $this->assertSame(900, SubscriptionPlan::Esencial->price());
        $this->assertSame(900, app(SeatLimitService::class)->monthlyTotal($business->fresh()));
        $this->assertSame(700, (int) $invoice->fresh()->amount);

        \Illuminate\Support\Facades\Cache::forget(BillingCatalog::CACHE_KEY);
    }

    public function test_promo_applies_only_to_that_business(): void
    {
        $owner = User::factory()->create();
        $promo = Business::create([
            'name' => 'Local Oferta',
            'type' => BusinessType::Colmado,
            'owner_id' => $owner->id,
            'is_active' => true,
            'subscription_plan' => SubscriptionPlan::Esencial,
            'promo_percent' => 50,
            'promo_note' => 'Lanzamiento',
        ]);
        $full = Business::create([
            'name' => 'Local Lista',
            'type' => BusinessType::Colmado,
            'owner_id' => $owner->id,
            'is_active' => true,
            'subscription_plan' => SubscriptionPlan::Esencial,
        ]);
        $seats = app(SeatLimitService::class);

        $this->assertTrue($promo->hasActivePromo());
        $this->assertSame(350, $seats->monthlyTotal($promo));
        $this->assertSame(700, $seats->monthlyTotal($full));

        $promo->forceFill(['promo_until' => now()->subDay()->toDateString()])->save();
        $this->assertFalse($promo->fresh()->hasActivePromo());
        $this->assertSame(700, $seats->monthlyTotal($promo->fresh()));
    }
}
