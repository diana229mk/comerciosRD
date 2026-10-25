<?php

namespace App\Http\Middleware;

use App\Models\Business;
use App\Models\User;
use App\Services\AuthContextResolver;
use Closure;
use Filament\Facades\Filament;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Dueño/admin ven el dashboard. Preventa, cajero e inventario no:
 * si pisan esa ruta, se les manda a su pantalla antes del 403 de Filament.
 */
class RedirectToRoleHome
{
    public function handle(Request $request, Closure $next): Response
    {
        if (! $request->routeIs('filament.admin.pages.dashboard')) {
            return $next($request);
        }

        $user = $request->user();
        if (! $user instanceof User) {
            return $next($request);
        }

        $role = session(AuthContextResolver::SESSION_ROLE);
        $tenant = Filament::getTenant();
        if ($tenant instanceof Business && $role === null) {
            $role = $user->roleInBusiness((string) $tenant->getKey());
        }
        if ($role === 'manager') {
            $role = 'supervisor';
        }

        if (! in_array($role, ['seller', 'cashier', 'inventory'], true)) {
            return $next($request);
        }

        $dest = session(AuthContextResolver::SESSION_PANEL_DESTINATION);
        if ((! is_string($dest) || $dest === '') && $tenant instanceof Business) {
            $dest = app(AuthContextResolver::class)->panelDestination($user, $tenant, $role);
        }

        if (! is_string($dest) || $dest === '') {
            return $next($request);
        }

        $destPath = parse_url($dest, PHP_URL_PATH) ?: $dest;
        if (trim($request->getPathInfo(), '/') === trim((string) $destPath, '/')) {
            return $next($request);
        }

        return new RedirectResponse($dest);
    }
}
