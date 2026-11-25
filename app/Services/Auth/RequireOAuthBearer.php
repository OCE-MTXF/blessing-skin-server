<?php

namespace App\Services\Auth;

use Closure;
use Illuminate\Http\Request;
use Laravel\Passport\Passport;

final class RequireOAuthBearer
{
    public function handle(Request $request, Closure $next)
    {
        $headers = $request->headers->all('authorization');
        if (count($headers) !== 1 || preg_match('/\ABearer [a-zA-Z0-9_.~+\/-]+=*\z/i', $headers[0]) !== 1) {
            return response()->json(['message' => 'Unauthenticated.'], 401, ['WWW-Authenticate' => 'Bearer']);
        }

        // Never authenticate a stateless API call using Passport's transient cookie.
        $request->cookies->remove(Passport::cookie());

        return $next($request);
    }
}
