<?php

namespace App\Services;

use App\Enums\SubscriptionPlan;
use App\Models\Business;
use App\Models\User;

/**
 * Tope de usuarios activos por negocio según el plan contratado.
 * No desactiva a nadie que ya esté dentro; solo impide sumar más.
 */
class SeatLimitService
{
    public function plan(Business $business): SubscriptionPlan
    {
        return SubscriptionPlan::tryFromMixed($business->subscription_plan ?? null);
    }

    public function extraSeats(Business $business): int
    {
        return max(0, (int) ($business->extra_seats ?? 0));
    }

    public function includedSeats(Business $business): int
    {
        return $this->plan($business)->includedSeats();
    }

    public function maxSeats(Business $business): int
    {
        return $this->includedSeats($business) + $this->extraSeats($business);
    }

    public function activeSeats(Business $business): int
    {
        return $business->users()
            ->wherePivot('is_active', true)
            ->count();
    }

    public function remaining(Business $business): int
    {
        return max(0, $this->maxSeats($business) - $this->activeSeats($business));
    }

    public function canAdd(Business $business, int $adding = 1): bool
    {
        return $this->activeSeats($business) + $adding <= $this->maxSeats($business);
    }

    public function listPrice(Business $business): int
    {
        $extraPrice = app(BillingCatalog::class)->extraSeatPrice();

        return $this->plan($business)->price() + ($this->extraSeats($business) * $extraPrice);
    }

    public function monthlyTotal(Business $business): int
    {
        $list = $this->listPrice($business);
        $percent = $business->promoPercent();
        if ($percent <= 0) {
            return $list;
        }

        return (int) round($list * (100 - $percent) / 100);
    }

    /**
     * @return array{
     *     plan: SubscriptionPlan,
     *     included: int,
     *     extra: int,
     *     max: int,
     *     used: int,
     *     remaining: int,
     *     monthly: int,
     *     list: int,
     *     promo: int
     * }
     */
    public function snapshot(Business $business): array
    {
        $plan = $this->plan($business);

        return [
            'plan' => $plan,
            'included' => $this->includedSeats($business),
            'extra' => $this->extraSeats($business),
            'max' => $this->maxSeats($business),
            'used' => $this->activeSeats($business),
            'remaining' => $this->remaining($business),
            'monthly' => $this->monthlyTotal($business),
            'list' => $this->listPrice($business),
            'promo' => $business->promoPercent(),
        ];
    }

    public function denyMessage(Business $business): string
    {
        $snap = $this->snapshot($business);

        return sprintf(
            'El plan %s incluye %d usuarios (%s). Usados: %d de %d. Sube de plan o pide un ajuste de asientos extra (RD$ %s c/u).',
            $snap['plan']->label(),
            $snap['included'],
            $snap['plan']->summary(),
            $snap['used'],
            $snap['max'],
            number_format(app(BillingCatalog::class)->extraSeatPrice(), 0)
        );
    }

    public function userOccupiesSeat(Business $business, User $user): bool
    {
        return $business->users()
            ->where('users.id', $user->getKey())
            ->wherePivot('is_active', true)
            ->exists();
    }
}
