<?php

namespace App\Http\Middleware;

use App\Models\Business;
use App\Models\User;
use App\Services\AuthContextResolver;
use App\Support\TenantContext;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Resuelve el comercio de /caja/{tenant} sin bootear Filament.
 * Misma regla de acceso que App\Filament\Pages\Pos::canAccess().
 */
class BindPosTenant
{
    public function handle(Request $request, Closure $next): Response
    {
        $slug = (string) $request->route('tenant');
        $business = Business::query()
            ->where('slug', $slug)
            ->where('is_active', true)
            ->first();

        if (! $business) {
            abort(404);
        }

        $user = $request->user();
        if (! $user instanceof User) {
            abort(403);
        }

        if (! $user->canAccessTenant($business)) {
            abort(403, 'Sin acceso a este comercio.');
        }

        TenantContext::apply((string) $business->getKey());
        app(AuthContextResolver::class)->apply($user, $business);

        $role = $user->roleInBusiness((string) $business->getKey());
        if ($role === 'manager') {
            $role = 'supervisor';
        }

        if (! in_array($role, ['owner', 'admin', 'supervisor', 'cashier'], true)) {
            abort(403);
        }

        $permissions = config('permissions.roles.'.$role, []);
        if (! in_array('*', $permissions, true) && ! in_array('create_sales', $permissions, true)) {
            abort(403);
        }

        $request->attributes->set('posBusiness', $business);

        return $next($request);
    }
}
