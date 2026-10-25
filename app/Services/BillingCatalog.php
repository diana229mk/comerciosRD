<?php

namespace App\Services;

use App\Enums\SubscriptionPlan;
use App\Models\PlatformSetting;
use Illuminate\Support\Facades\Cache;

/**
 * Precios de planes ComerciosRD. Config es el default; Master puede subirlos
 * sin tocar código. Las facturas ya pagadas no se reescriben.
 */
class BillingCatalog
{
    public const SETTING_KEY = 'billing.prices';

    public const CACHE_KEY = 'billing.catalog';

    /**
     * @return array{esencial: int, equipo: int, completo: int, extra_seat: int}
     */
    public function all(): array
    {
        return Cache::remember(self::CACHE_KEY, 60, function (): array {
            $defaults = [
                'esencial' => (int) config('billing.plans.esencial.price', 700),
                'equipo' => (int) config('billing.plans.equipo.price', 1000),
                'completo' => (int) config('billing.plans.completo.price', 1300),
                'extra_seat' => (int) config('billing.extra_seat_dop', 300),
            ];

            try {
                $row = PlatformSetting::query()->find(self::SETTING_KEY);
            } catch (\Throwable) {
                return $defaults;
            }

            $saved = is_array($row?->value) ? $row->value : [];
            foreach ($defaults as $key => $fallback) {
                if (isset($saved[$key]) && is_numeric($saved[$key])) {
                    $defaults[$key] = max(0, (int) $saved[$key]);
                }
            }

            return $defaults;
        });
    }

    public function planPrice(SubscriptionPlan|string $plan): int
    {
        $key = $plan instanceof SubscriptionPlan ? $plan->value : $plan;

        return (int) ($this->all()[$key] ?? 0);
    }

    public function extraSeatPrice(): int
    {
        return (int) ($this->all()['extra_seat'] ?? 300);
    }

    /**
     * @param  array{esencial?: int, equipo?: int, completo?: int, extra_seat?: int}  $prices
     */
    public function save(array $prices): void
    {
        $current = $this->all();
        foreach (['esencial', 'equipo', 'completo', 'extra_seat'] as $key) {
            if (isset($prices[$key]) && is_numeric($prices[$key])) {
                $current[$key] = max(0, (int) $prices[$key]);
            }
        }

        PlatformSetting::query()->updateOrCreate(
            ['key' => self::SETTING_KEY],
            ['value' => $current],
        );

        Cache::forget(self::CACHE_KEY);
    }
}
