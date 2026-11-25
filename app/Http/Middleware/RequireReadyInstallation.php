<?php

namespace App\Http\Middleware;

use App\Services\Installation\Readiness;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Http\Response as HttpResponse;
use Symfony\Component\HttpFoundation\Response;

final class RequireReadyInstallation
{
    public function __construct(private Readiness $readiness)
    {
    }

    public function handle(Request $request, Closure $next): Response
    {
        // These paths stay unavailable before and after installation, on every HTTP method.
        if ($request->is('setup', 'setup/*', 'admin/update', 'admin/update/*', 'plugins', 'plugins/*', 'lang', 'lang/*')) {
            return $this->plain('Not Found', 404);
        }
        if (!$this->readiness->isReady()) {
            return $this->plain('Service Unavailable', 503);
        }
        if ($request->is('healthz') && ($request->isMethod('GET') || $request->isMethod('HEAD'))) {
            return $this->plain('ready', 200);
        }

        return $next($request);
    }

    /**
     * Answer without the response factory: it resolves the redirector, the session
     * and therefore the encrypter, which requires APP_KEY. An instance that is
     * closed or not yet provisioned must still report its state as a status code.
     */
    private function plain(string $body, int $status): Response
    {
        return new HttpResponse($body, $status, [
            'Cache-Control' => 'no-store',
            'Content-Type' => 'text/plain; charset=UTF-8',
        ]);
    }
}