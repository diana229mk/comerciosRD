<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Redirector as LaravelRedirector;
use Livewire\Features\SupportRedirects\Redirector as LivewireRedirector;
use Livewire\Features\SupportRedirects\SupportRedirects;
use Symfony\Component\HttpFoundation\Response;

/**
 * Livewire reemplaza el binding `redirect` al montar un componente.
 * Si la página aborta 403/404 antes de dehydrate, el Redirector de Livewire
 * queda pegado al proceso (php artisan serve y PHPUnit) y el siguiente
 * `redirect()` explota con 500.
 */
class RestoreLaravelRedirector
{
    public function handle(Request $request, Closure $next): Response
    {
        self::restore();

        try {
            $response = $next($request);
        } catch (\Throwable $e) {
            self::restore();
            throw $e;
        }

        self::restore();

        if ($response instanceof LivewireRedirector) {
            $target = self::targetFromLivewire($response) ?? url('/admin/login');

            return new RedirectResponse($target);
        }

        return $response;
    }

    public static function restore(): void
    {
        $stack = SupportRedirects::$redirectorCacheStack;
        $laravel = null;

        while (! empty($stack)) {
            $cached = array_pop($stack);
            if ($cached instanceof LaravelRedirector && ! $cached instanceof LivewireRedirector) {
                $laravel = $cached;
                break;
            }
        }

        SupportRedirects::$redirectorCacheStack = [];

        if (! $laravel instanceof LaravelRedirector) {
            $laravel = new LaravelRedirector(app('url'));
        }

        if (app()->bound('session.store')) {
            $laravel->setSession(app('session.store'));
        }

        app()->instance('redirect', $laravel);
    }

    protected static function targetFromLivewire(LivewireRedirector $redirector): ?string
    {
        try {
            $component = (new \ReflectionClass($redirector))
                ->getProperty('component')
                ->getValue($redirector);

            if (is_object($component) && method_exists($component, 'redirect')) {
                $url = data_get($component->getEffects(), 'redirect')
                    ?? data_get($component, 'redirectTo');
                if (is_string($url) && $url !== '') {
                    return $url;
                }
            }
        } catch (\Throwable) {
        }

        return null;
    }
}
