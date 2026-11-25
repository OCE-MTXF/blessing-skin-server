<?php

namespace App\Http\Middleware;

use App\Models\User;
use App\Services\Auth\AccountSecurity;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;

final class EnforceAccountVersion
{
    public function handle(Request $request, Closure $next)
    {
        $guard = Auth::guard('web');
        $uid = $request->session()->get($guard->getName());
        $user = $guard->user();
        if (($user instanceof User || $uid !== null) && (!$user instanceof User
            || $user->account_version < 1 || $request->session()->get(AccountSecurity::SESSION_VERSION) !== $user->account_version)) {
            $guard->logoutCurrentDevice();
            $request->session()->invalidate();
            $request->session()->regenerateToken();
        }

        return $next($request);
    }
}
