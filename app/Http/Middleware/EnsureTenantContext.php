<?php

namespace App\Http\Middleware;

use App\Models\Business;
use App\Models\User;
use App\Services\AuthContextResolver;
use Closure;
use Filament\Facades\Filament;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Symfony\Component\HttpFoundation\Response;

/**
 * Sincroniza el contexto de tenant en cada request del panel admin.
 *
 * Debe registrarse como tenantMiddleware persistente en AdminPanelProvider.
 *
 * Prepara el contexto para:
 *  - Global Scope (BusinessScope)
 *  - Trait BelongsToBusiness (creating / assert)
 *  - Jobs / queries fuera de Resources
 *  - Capas futuras de seguridad (audit, rate-limit, feature flags)
 */
class EnsureTenantContext
{
    public function handle(Request $request, Closure $next): Response
    {
        $tenant = Filament::getTenant();

        // Livewire (/livewire/update) no lleva {tenant} en la ruta.
        // IdentifyTenant se salta y Filament::getTenant() queda null → 403 al crear.
        if (! $tenant instanceof Business) {
            $tenant = $this->tenantFromSession($request->user());
            if ($tenant instanceof Business) {
                try {
                    Filament::setTenant($tenant);
                } catch (\Throwable) {
                }
            }
        }

        if (! $tenant instanceof Business) {
            return $next($request);
        }

        $businessId = (string) $tenant->getKey();
        $user = $request->user();

        // Defensa extra: membresía activa (Filament ya llama canAccessTenant,
        // pero revalidamos aquí por si el middleware se reutiliza fuera del panel).
        if ($user !== null && method_exists($user, 'canAccessTenant')) {
            if (! $user->canAccessTenant($tenant)) {
                // FASE 3: en vez de 404 mudo, devolver al login con mensaje
                // claro. Si el user ya no tiene ningún negocio, cerrar sesión.
                $message = ($user instanceof User && ! $user->activeBusinesses()->exists())
                    ? 'Tu cuenta no tiene ningún negocio asignado. Contacta al dueño.'
                    : 'Tu acceso a este negocio fue desactivado. Contacta al dueño.';

                if ($user instanceof User && ! $user->activeBusinesses()->exists()) {
                    Auth::logout();
                    $request->session()->invalidate();
                    $request->session()->regenerateToken();
                }

                if ($request->hasSession()) {
                    $request->session()->put('url.intended', $request->fullUrl());
                    $request->session()->flash('status', $message);
                }

                return new RedirectResponse(url('/admin/login'));
            }
        }

        // FASE 3: rehidratar contexto si la sesión lo perdió (config:cache,
        // cookie que sobrevivió a un reinicio, etc.). Idempotente.
        if ($user instanceof User) {
            app(AuthContextResolver::class)->apply($user, $tenant);
        } else {
            // Sin usuario autenticado pero con tenant en el path: solo sesión.
            session(['current_business_id' => $businessId]);
            app()->instance('currentBusinessId', $businessId);
            app()->instance('currentBusiness', $tenant);
        }

        return $next($request);
    }

    protected function tenantFromSession(?User $user): ?Business
    {
        if (! $user instanceof User) {
            return null;
        }

        $businessId = session(AuthContextResolver::SESSION_BUSINESS_ID)
            ?: (app()->bound('currentBusinessId') ? app('currentBusinessId') : null)
            ?: $user->current_business_id;

        if (! is_string($businessId) && ! is_numeric($businessId)) {
            return null;
        }
        $businessId = (string) $businessId;
        if ($businessId === '') {
            return null;
        }

        $business = Business::query()->whereKey($businessId)->where('is_active', true)->first();
        if (! $business instanceof Business) {
            return null;
        }

        return $user->canAccessTenant($business) ? $business : null;
    }
}
