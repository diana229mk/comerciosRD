<?php

use App\Http\Middleware\CheckBusinessFeature;
use App\Http\Middleware\ContentSecurityPolicy;
use App\Http\Middleware\RestoreLaravelRedirector;
use App\Http\Middleware\SecurityHeaders;
use App\Http\Middleware\SessionFingerprint;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
    )
    ->withMiddleware(function (Middleware $middleware): void {
        // Global: headers de seguridad en todas las respuestas
        $middleware->append(SecurityHeaders::class);

        // Stack web: CSP + fingerprint de sesión
        $middleware->web(prepend: [
            RestoreLaravelRedirector::class,
        ]);
        $middleware->web(append: [
            ContentSecurityPolicy::class,
            SessionFingerprint::class,
        ]);

        // `auth` (caja, tickets, 2FA) no tiene ruta `login`; Filament usa /admin/login.
        $middleware->redirectGuestsTo(fn () => url('/admin/login'));

        $middleware->alias([
            'business.feature' => CheckBusinessFeature::class,
            'session.fingerprint' => SessionFingerprint::class,
            'security.headers' => SecurityHeaders::class,
            'security.csp' => ContentSecurityPolicy::class,
            'pos.tenant' => \App\Http\Middleware\BindPosTenant::class,
        ]);
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        $exceptions->render(function (\Throwable $e, \Illuminate\Http\Request $request) {
            RestoreLaravelRedirector::restore();

            return null;
        });

        $exceptions->render(function (\Symfony\Component\HttpKernel\Exception\HttpException $e, \Illuminate\Http\Request $request) {
            $status = $e->getStatusCode();
            if ($status !== 403 && $status !== 404) {
                return null;
            }

            if ($request->is('master/*', 'admin/login', 'admin/logout', 'caja/*', 'livewire/*')) {
                return null;
            }

            if ($status === 404 && ! $request->is('admin', 'admin/*')) {
                return null;
            }

            $user = $request->user();
            if (! $user instanceof \App\Models\User) {
                return null;
            }

            $dest = session(\App\Services\AuthContextResolver::SESSION_PANEL_DESTINATION);
            try {
                $tenant = filament()->getTenant();
                if ($tenant instanceof \App\Models\Business) {
                    $dest = app(\App\Services\AuthContextResolver::class)
                        ->panelDestination($user, $tenant);
                } elseif (! is_string($dest) || $dest === '') {
                    $dest = app(\App\Services\AuthContextResolver::class)->apply($user)['panel_url'] ?? $dest;
                }
            } catch (\Throwable) {
            }

            if (! is_string($dest) || $dest === '') {
                return null;
            }

            $destPath = parse_url($dest, PHP_URL_PATH) ?: $dest;
            if (trim($request->getPathInfo(), '/') === trim((string) $destPath, '/')) {
                return null;
            }

            return new \Illuminate\Http\RedirectResponse($dest);
        });
    })->create();
