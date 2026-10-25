<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Headers de seguridad HTTP.
 *
 * Protege contra:
 *  - MIME sniffing
 *  - Clickjacking
 *  - XSS básico (legacy header; la defensa real es CSP)
 *  - Filtrado de referrer
 *  - APIs de dispositivo no usadas
 *
 * CSP detallado: ContentSecurityPolicy middleware (config/security.php).
 */
class SecurityHeaders
{
    public function handle(Request $request, Closure $next): Response
    {
        /** @var Response $response */
        $response = $next($request);

        $cfg = config('security.headers', []);

        // Evita que el navegador MIME-sniff
        $response->headers->set(
            'X-Content-Type-Options',
            $cfg['x_content_type_options'] ?? 'nosniff'
        );

        // Evita que la página se cargue en un iframe (clickjacking)
        $response->headers->set(
            'X-Frame-Options',
            $cfg['x_frame_options'] ?? 'DENY'
        );

        // Protección XSS legacy del navegador
        $response->headers->set(
            'X-XSS-Protection',
            $cfg['x_xss_protection'] ?? '1; mode=block'
        );

        // Control de referrer
        $response->headers->set(
            'Referrer-Policy',
            $cfg['referrer_policy'] ?? 'strict-origin-when-cross-origin'
        );

        // Permissions Policy (antes Feature-Policy)
        $response->headers->set(
            'Permissions-Policy',
            $cfg['permissions_policy'] ?? 'camera=(), microphone=(), geolocation=()'
        );

        // HSTS solo en HTTPS
        if (($cfg['hsts_enabled'] ?? true) && $request->secure()) {
            $maxAge = (int) ($cfg['hsts_max_age'] ?? 31536000);
            $hsts = "max-age={$maxAge}";
            if ($cfg['hsts_include_subdomains'] ?? true) {
                $hsts .= '; includeSubDomains';
            }
            if ($cfg['hsts_preload'] ?? false) {
                $hsts .= '; preload';
            }
            $response->headers->set('Strict-Transport-Security', $hsts);
        }

        // Cross-Origin isolation (defensa contra Spectre-like + tabnabbing)
        $response->headers->set(
            'Cross-Origin-Opener-Policy',
            $cfg['coop'] ?? 'same-origin'
        );
        $response->headers->set(
            'Cross-Origin-Resource-Policy',
            $cfg['corp'] ?? 'same-origin'
        );

        // Evitar cache de respuestas autenticadas
        if ($request->user()) {
            $response->headers->set('Cache-Control', 'no-store, no-cache, must-revalidate, private');
            $response->headers->set('Pragma', 'no-cache');
        }

        return $response;
    }
}
