<?php

namespace Tests;

use App\Http\Middleware\CanonicalOrigin;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

final class CanonicalOriginTest extends TestCase
{
    public function testForwardingIsLimitedToConfiguredPeersAndTheCanonicalHost(): void
    {
        config(['app.url' => 'https://site.example.test', 'app.trusted_proxies' => '192.0.2.0/28']);
        $next = static fn (Request $request): Response => new Response((string) $request->ip());
        $middleware = new CanonicalOrigin;
        $proxyHeaders = ['HTTP_X_FORWARDED_PROTO' => 'https', 'HTTP_X_FORWARDED_FOR' => '203.0.113.42'];

        try {
            $trusted = Request::create('http://site.example.test/', server: $proxyHeaders + ['REMOTE_ADDR' => '192.0.2.2']);
            self::assertSame('203.0.113.42', $middleware->handle($trusted, $next)->getContent());

            $untrusted = Request::create('http://site.example.test/', server: $proxyHeaders + ['REMOTE_ADDR' => '198.51.100.2']);
            self::assertSame(400, $middleware->handle($untrusted, $next)->getStatusCode());

            $otherOrigin = Request::create('https://other.example.test/');
            self::assertSame(400, $middleware->handle($otherOrigin, $next)->getStatusCode());
        } finally {
            Request::setTrustedProxies([], 0);
        }
    }
}
