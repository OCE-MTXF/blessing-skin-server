<?php

namespace App\Services\Auth;

use Illuminate\Http\Request;
use Laravel\Passport\Contracts\AuthorizationViewResponse;
use Laravel\Passport\Exceptions\AuthenticationException;
use Laravel\Passport\Http\Controllers\AuthorizationController;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;

final class OAuthAuthorizationController
{
    public function __invoke(
        AuthorizationController $passport,
        ServerRequestInterface $psrRequest,
        Request $request,
        ResponseInterface $psrResponse,
        AuthorizationViewResponse $viewResponse,
    ) {
        try {
            return $passport->authorize($psrRequest, $request, $psrResponse, $viewResponse);
        } catch (AuthenticationException $e) {
            // Catch Passport's login prompt here, before Laravel turns it into a
            // response. Only preserve the canonical, local authorization URL.
            $target = $request->getRequestUri();
            if (!$request->expectsJson() && strlen($target) <= 4096
                && preg_match('~\A/oauth/authorize\?[^\\\\\x00-\x20\x7f]*\z~D', $target) === 1) {
                $request->session()->put('last_requested_path', $target);

                return redirect()->route('auth.login');
            }

            throw $e;
        }
    }
}
