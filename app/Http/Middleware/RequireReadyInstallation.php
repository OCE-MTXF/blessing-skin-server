<?php

namespace App\Http\Middleware;

use App\Services\Installation\Readiness;
use Closure;
use Illuminate\Http\Request;
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
            return response('Not Found', 404, $this->headers());
        }
        if (!$this->readiness->isReady()) {
            return response('Service Unavailable', 503, $this->headers());
        }
        if ($request->is('healthz') && ($request->isMethod('GET') || $request->isMethod('HEAD'))) {
            return response('ready', 200, $this->headers());
        }

        return $next($request);
    }

    private function headers(): array
    {
        return ['Cache-Control' => 'no-store', 'Content-Type' => 'text/plain; charset=UTF-8'];
    }
}
