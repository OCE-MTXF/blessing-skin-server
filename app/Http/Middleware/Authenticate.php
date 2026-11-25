<?php

namespace App\Http\Middleware;

use Illuminate\Auth\Middleware\Authenticate as Middleware;

class Authenticate extends Middleware
{
    protected function redirectTo($request)
    {
        if ($request->expectsJson() || $request->is('api', 'api/*', 'oauth/token')) {
            return null;
        }

        if ($request->hasSession() && $request->isMethod('GET')) {
            // Only a local request target can be used as a post-login return URL.
            $target = $request->getRequestUri();
            if (preg_match('~\A/(?!/)[^\\\\\x00-\x20\x7f]*\z~D', $target) === 1) {
                $request->session()->put('last_requested_path', $target);
            }
            $request->session()->put('msg', trans('auth.check.anonymous'));
        }

        return route('auth.login');
    }
}
