<?php

namespace App\Filament\Pages;

use App\Filament\Resources\CustomerResource;
use App\Filament\Resources\OrderResource;
use App\Filament\Resources\QuoteResource;
use App\Models\Business;
use App\Models\User;
use App\Services\AuthContextResolver;
use Filament\Facades\Filament;
use Filament\Pages\Page;

/**
 * Pantalla de aterrizaje del preventa. No depende del dashboard ni de
 * módulos de ferretería: si el usuario es seller, siempre puede entrar.
 */
class PreventaHome extends Page
{
    protected static ?string $navigationIcon = 'heroicon-o-clipboard-document-check';

    protected static ?string $navigationLabel = 'Preventa';

    protected static ?string $title = 'Preventa';

    protected static ?string $navigationGroup = 'Ventas';

    protected static ?int $navigationSort = -1;

    protected static ?string $slug = 'preventa';

    protected static string $view = 'filament.pages.preventa-home';

    public static function canAccess(): bool
    {
        $role = self::currentRole();

        return in_array($role, ['seller', 'owner', 'admin', 'supervisor'], true);
    }

    public static function shouldRegisterNavigation(): bool
    {
        return self::currentRole() === 'seller';
    }

    protected static function currentRole(): ?string
    {
        $user = auth()->user();
        if (! $user instanceof User) {
            return null;
        }

        $tenant = Filament::getTenant();
        if ($tenant instanceof Business) {
            if (! $user->canAccessTenant($tenant)) {
                return null;
            }

            $role = $user->roleInBusiness((string) $tenant->getKey())
                ?: session(AuthContextResolver::SESSION_ROLE);
        } else {
            $role = session(AuthContextResolver::SESSION_ROLE);
            $businessId = session(AuthContextResolver::SESSION_BUSINESS_ID);
            if (! is_string($businessId) || $businessId === '' || ! $user->belongsToBusiness($businessId)) {
                return null;
            }
        }

        return $role === 'manager' ? 'supervisor' : $role;
    }

    /**
     * @return array{quotes: ?string, orders: ?string, customers: ?string}
     */
    public function links(): array
    {
        $tenant = Filament::getTenant();
        if (! $tenant instanceof Business) {
            return ['quotes' => null, 'orders' => null, 'customers' => null];
        }

        $quotes = null;
        $orders = null;
        $customers = null;
        $slug = (string) $tenant->slug;

        try {
            if (QuoteResource::canViewAny()) {
                $quotes = route('filament.admin.resources.quotes.index', ['tenant' => $slug], absolute: false);
            }
        } catch (\Throwable) {
        }

        try {
            if (OrderResource::canViewAny()) {
                $orders = route('filament.admin.resources.orders.index', ['tenant' => $slug], absolute: false);
            }
        } catch (\Throwable) {
        }

        try {
            if (CustomerResource::canViewAny()) {
                $customers = route('filament.admin.resources.customers.index', ['tenant' => $slug], absolute: false);
            }
        } catch (\Throwable) {
        }

        return compact('quotes', 'orders', 'customers');
    }
}
