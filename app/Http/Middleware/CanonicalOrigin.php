<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

final class CanonicalOrigin
{
    public function handle(Request $request, Closure $next): Response
    {
        // Only explicitly listed network peers can supply client IP and scheme;
        // forwarded host/port are never used. The proxy must preserve Host.
        $proxies = array_filter(array_map('trim', explode(',', (string) config('app.trusted_proxies'))), self::validNetwork(...));
        Request::setTrustedProxies(array_values($proxies), Request::HEADER_X_FORWARDED_FOR | Request::HEADER_X_FORWARDED_PROTO);

        if ($request->getPathInfo() === '/healthz' && in_array($request->getMethod(), ['GET', 'HEAD'], true)) {
            return $next($request);
        }

        $origin = parse_url((string) config('app.url'));
        $scheme = strtolower($origin['scheme'] ?? '');
        $port = $origin['port'] ?? ($scheme === 'https' ? 443 : 80);
        if (!in_array($scheme, ['http', 'https'], true)
            || !isset($origin['host'])
            || !hash_equals(strtolower($origin['host']), strtolower($request->getHost()))
            || $port !== $request->getPort()
            || $scheme !== $request->getScheme()) {
            return response('Bad Request', 400, ['Content-Type' => 'text/plain; charset=UTF-8', 'Cache-Control' => 'no-store']);
        }

        return $next($request);
    }

    private static function validNetwork(string $value): bool
    {
        $parts = explode('/', $value);
        if (count($parts) > 2 || !filter_var($parts[0], FILTER_VALIDATE_IP)) {
            return false;
        }
        $bits = $parts[1] ?? null;

        return $bits === null || (ctype_digit($bits) && (int) $bits >= 1 && (int) $bits <= (str_contains($parts[0], ':') ? 128 : 32));
    }
}
