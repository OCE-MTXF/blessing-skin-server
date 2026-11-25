<?php

namespace App\Http\Middleware;

use App\Services\Auth\AccountIdentity;
use Closure;
use Illuminate\Http\Request;

class EnsureEmailFilled
{
    public function handle(Request $request, Closure $next, ?string $guard = null)
    {
        $hasEmail = AccountIdentity::canMail($request->user($guard)?->email);
        if ($guard === 'oauth') {
            abort_unless($hasEmail, 403);

            return $next($request);
        }

        $binding = $request->routeIs('auth.bind.*');
        if ($binding && $hasEmail) {
            return redirect()->route('user.home');
        }
        if (!$binding && !$hasEmail) {
            return redirect()->route('auth.bind.view');
        }

        return $next($request);
    }
}
