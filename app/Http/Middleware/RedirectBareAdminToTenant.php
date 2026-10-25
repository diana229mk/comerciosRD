<?php

namespace App\Http\Middleware;

use App\Models\User;
use App\Services\AuthContextResolver;
use Closure;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * /admin sin slug y /admin/{slug} (home de Filament) no son pantallas de
 * trabajo. El dueño va al dashboard; cajero/preventa/inventario a su puesto.
 * Evita el 404 de RedirectToTenantController cuando getUrl() queda vacío.
 */
class RedirectBareAdminToTenant
{
    public function handle(Request $request, Closure $next): Response
    {
        if (! $request->routeIs('filament.admin.tenant', 'filament.admin.home')) {
            return $next($request);
        }

        $user = $request->user();
        if (! $user instanceof User) {
            return $next($request);
        }

        $dest = $this->destination($user);
        if (! is_string($dest) || $dest === '') {
            return $next($request);
        }

        $destPath = parse_url($dest, PHP_URL_PATH) ?: $dest;
        if (trim($request->getPathInfo(), '/') === trim((string) $destPath, '/')) {
            return $next($request);
        }

        return new RedirectResponse($dest);
    }

    protected function destination(User $user): ?string
    {
        $dest = session(AuthContextResolver::SESSION_PANEL_DESTINATION);
        if (is_string($dest) && $dest !== '') {
            return $dest;
        }

        $ctx = app(AuthContextResolver::class)->apply($user);

        return is_string($ctx['panel_url'] ?? null) ? $ctx['panel_url'] : null;
    }
}
