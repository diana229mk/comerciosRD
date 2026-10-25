<?php

namespace App\Enums;

enum SubscriptionPlan: string
{
    case Esencial = 'esencial';
    case Equipo = 'equipo';
    case Completo = 'completo';

    public function label(): string
    {
        return (string) config('billing.plans.'.$this->value.'.label', $this->value);
    }

    public function price(): int
    {
        try {
            return app(\App\Services\BillingCatalog::class)->planPrice($this);
        } catch (\Throwable) {
            return (int) config('billing.plans.'.$this->value.'.price', 0);
        }
    }

    public function includedSeats(): int
    {
        return max(1, (int) config('billing.plans.'.$this->value.'.included_seats', 1));
    }

    public function summary(): string
    {
        return (string) config('billing.plans.'.$this->value.'.summary', $this->label());
    }

    /**
     * @return array<string, string>
     */
    public static function options(): array
    {
        $out = [];
        foreach (self::cases() as $plan) {
            $out[$plan->value] = sprintf(
                '%s — %s — RD$ %s/mes',
                $plan->label(),
                $plan->summary(),
                number_format($plan->price(), 0)
            );
        }

        return $out;
    }

    public static function tryFromMixed(mixed $value): self
    {
        if ($value instanceof self) {
            return $value;
        }

        $key = is_string($value) ? strtolower(trim($value)) : '';

        return self::tryFrom($key) ?? self::Esencial;
    }
}
