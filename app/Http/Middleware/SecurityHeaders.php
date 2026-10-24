<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Security headers on every response. The CSP keeps everything on this origin: phones never fetch from a
 * CDN, and the Weather page reaches Open-Meteo only through the farm's own proxy. Inline styles and scripts
 * (and eval) stay allowed because Livewire, Alpine and Filament need them. Geolocation is allowed for this
 * origin, for "Use my location" in Farm settings, and the camera for scanning cards and tags (Kudde).
 */
class SecurityHeaders
{
    public function handle(Request $request, Closure $next): Response
    {
        /** @var Response $response */
        $response = $next($request);

        $response->headers->set('X-Frame-Options', 'DENY');
        $response->headers->set('X-Content-Type-Options', 'nosniff');
        $response->headers->set('Referrer-Policy', 'strict-origin-when-cross-origin');
        $response->headers->set('Permissions-Policy', 'camera=(self), microphone=(), geolocation=(self)');
        $response->headers->set(
            'Content-Security-Policy',
            "default-src 'self'; script-src 'self' 'unsafe-inline' 'unsafe-eval'; "
            ."style-src 'self' 'unsafe-inline'; img-src 'self' data: blob:; font-src 'self' data:; "
            ."connect-src 'self'; frame-ancestors 'none'; base-uri 'self'; form-action 'self'",
        );

        return $response;
    }
}
