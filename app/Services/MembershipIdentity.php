<?php

namespace App\Services;

use App\Models\Business;
use App\Models\User;

/**
 * Resuelve quién entra al panel de un comercio.
 *
 * Hoy `users.email` sigue siendo UNIQUE global (login legado).
 * Esta clase es la costura para el futuro:
 *  - login con slug del negocio + email
 *  - o slug + `user_business.login_name` (PIN / usuario de caja por local)
 *
 * No borra la unique de email: eso es una migración de identidad aparte.
 */
class MembershipIdentity
{
    public function resolveUser(string $identifier, ?string $businessSlug = null): ?User
    {
        $identifier = strtolower(trim($identifier));
        if ($identifier === '') {
            return null;
        }

        $slug = $businessSlug !== null ? strtolower(trim($businessSlug)) : null;
        $slug = $slug === '' ? null : $slug;

        $business = null;
        if ($slug !== null) {
            $business = Business::query()
                ->where('slug', $slug)
                ->where('is_active', true)
                ->first();

            if (! $business) {
                return null;
            }
        }

        $byEmail = User::query()->where('email', $identifier)->first();
        if ($byEmail) {
            if ($business && ! $this->isActiveMember($byEmail, (string) $business->getKey())) {
                return null;
            }

            return $byEmail;
        }

        if (! $business) {
            return null;
        }

        return User::query()
            ->whereHas('businesses', function ($q) use ($business, $identifier): void {
                $q->where('businesses.id', $business->getKey())
                    ->where('user_business.is_active', true)
                    ->whereRaw('LOWER(user_business.login_name) = ?', [$identifier]);
            })
            ->first();
    }

    public function preferredTenant(User $user, ?string $businessSlug): ?Business
    {
        $slug = $businessSlug !== null ? strtolower(trim($businessSlug)) : null;
        if ($slug === null || $slug === '') {
            return null;
        }

        return $user->activeBusinesses()->where('businesses.slug', $slug)->first();
    }

    public function isActiveMember(User $user, string $businessId): bool
    {
        return $user->businesses()
            ->where('businesses.id', $businessId)
            ->wherePivot('is_active', true)
            ->exists();
    }
}
