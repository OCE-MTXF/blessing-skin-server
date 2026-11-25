<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

final class SecurityHeaders
{
    public function handle(Request $request, Closure $next): Response
    {
        return $this->apply($request, $next($request));
    }

    public function apply(Request $request, Response $response): Response
    {
        $headers = $response->headers;
        $headers->set('Content-Security-Policy', implode('; ', [
            "default-src 'self'", "base-uri 'self'", "object-src 'none'", "frame-ancestors 'none'",
            // Permit HTTPS redirects after OAuth consent without allowing HTTP form targets.
            "form-action 'self' https:", "script-src 'self'", "style-src 'self' 'unsafe-inline'",
            "img-src 'self' https: data: blob:", "font-src 'self' data:", "connect-src 'self'", "worker-src 'self' blob:",
        ]));
        $headers->set('X-Content-Type-Options', 'nosniff');
        $headers->set('X-Frame-Options', 'DENY');
        $headers->set('Referrer-Policy', 'no-referrer');
        $headers->set('Permissions-Policy', 'camera=(), microphone=(), geolocation=()');
        if ($request->isSecure()) {
            $headers->set('Strict-Transport-Security', 'max-age=31536000');
        }

        return $response;
    }
}
