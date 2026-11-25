<?php

namespace App\Http\Middleware;

use App\Models\User;
use Closure;
use Illuminate\Http\Request;

class RejectBannedUser
{
    public function handle(Request $request, Closure $next)
    {
        if ($request->user()?->permission === User::BANNED) {
            if ($request->expectsJson() || $request->is('api/*')) {
                return json(trans('auth.check.banned'), -1)->setStatusCode(403);
            }

            abort(403, trans('auth.check.banned'));
        }

        return $next($request);
    }
}
