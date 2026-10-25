<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Content-Security-Policy (Capa 4).
 * Compatible con Filament 3 / Livewire / Vite.
 */
class ContentSecurityPolicy
{
    public function handle(Request $request, Closure $next): Response
    {
        /** @var Response $response */
        $response = $next($request);

        if (! config('security.csp.enabled', true)) {
            return $response;
        }

        $directives = config('security.csp.directives', []);
        $parts = [];

        foreach ($directives as $name => $values) {
            $parts[] = $name.' '.implode(' ', $values);
        }

        $header = config('security.csp.report_only', false)
            ? 'Content-Security-Policy-Report-Only'
            : 'Content-Security-Policy';

        $response->headers->set($header, implode('; ', $parts));

        return $response;
    }
}
