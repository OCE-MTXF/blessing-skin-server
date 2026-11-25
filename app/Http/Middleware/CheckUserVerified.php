<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;

class CheckUserVerified
{
    public function handle(Request $request, Closure $next)
    {
        abort_unless($request->user() !== null && (!option('require_verification') || $request->user()->verified), 403, trans('auth.check.verified'));

        return $next($request);
    }
}
