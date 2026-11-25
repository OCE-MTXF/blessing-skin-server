<?php

namespace App\Services\Auth;

use App\Models\User;
use Closure;
use Illuminate\Http\Exceptions\HttpResponseException;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Laravel\Passport\Passport;
use Symfony\Component\HttpFoundation\Response;

final class OAuthBoundary
{
    private const CONSENT = 'auth.oauth_consent';

    public function __construct(private AccountSecurity $security)
    {
    }

    public function handle(Request $request, Closure $next, string $operation): Response
    {
        if ($operation === 'token' && !in_array($request->input('grant_type'), ['authorization_code', 'refresh_token'], true)) {
            return $this->sensitive(response()->json(['error' => 'unsupported_grant_type'], 400));
        }
        if ($operation === 'authorize' && $request->query('response_type') !== 'code') {
            return $this->sensitive(response()->json(['error' => 'unsupported_response_type'], 400));
        }

        return $this->security->serialized(function () use ($request, $next, $operation) {
            if ($operation === 'token') {
                return $this->commitIfAccepted($next($request));
            }

            $actor = Auth::guard('web')->user();
            $user = null;
            if ($actor instanceof User) {
                $user = $this->security->lockUser($actor->uid);
                $this->security->assertCurrent($actor, $user);
                abort_unless($this->security->allowsOAuth($user), 403);
            }
            if ($operation === 'authorize') {
                $request->session()->forget([self::CONSENT, 'authToken', 'authRequest']);
            } elseif ($operation === 'consent') {
                $consent = $request->session()->pull(self::CONSENT);
                abort_unless($user && is_array($consent) && $consent['uid'] === $user->uid
                    && $consent['version'] === $user->account_version && $consent['expires'] > time()
                    && $consent['client'] === $this->clientFingerprint($consent['client_id']), 403);
            }

            $response = $next($request);
            if (in_array($operation, ['authorize', 'consent'], true) && $response->isRedirect()) {
                $response = $this->authorizationRedirect($response, $operation);
            }
            $response = $this->commitIfAccepted($response, in_array($operation, ['authorize', 'consent'], true));
            if ($operation === 'authorize' && $user && $request->session()->has(['authToken', 'authRequest'])) {
                $clientId = $request->query('client_id');
                if (is_string($clientId) && ($fingerprint = $this->clientFingerprint($clientId)) !== null) {
                    $request->session()->put(self::CONSENT, [
                        'uid' => $user->uid, 'version' => $user->account_version, 'expires' => time() + 300,
                        'client_id' => $clientId, 'client' => $fingerprint,
                    ]);
                }
            }

            return $response;
        });
    }

    private function authorizationRedirect(Response $response, string $operation): Response
    {
        $location = $response->headers->get('Location');
        // The authorization controller can also send an unauthenticated user to our own login page.
        if ($operation === 'authorize' && $location === route('auth.login')) {
            return $response;
        }

        // Passport validates the registered destination (including the RFC 8252 port rule).
        // Validate its result again for the site's HTTPS/loopback policy, including legacy clients.
        $scheme = is_string($location) ? OAuthRedirectUri::allowedScheme($location) : null;
        if ($scheme === null) {
            throw new HttpResponseException($this->sensitive(response()->json(['error' => 'invalid_request'], 400)));
        }
        if ($operation !== 'consent' || $scheme !== 'http') {
            return $response;
        }

        // Chromium can enforce form-action on form redirects, including HTTP loopback.
        // End the form submission here and offer normal link navigation without relaxing CSP.
        return response()->view('auth.oauth-return', [
            'callback' => $location,
            'lang' => str_replace('_', '-', app()->getLocale()),
        ], 200, ['X-Robots-Tag' => 'noindex, nofollow']);
    }

    private function commitIfAccepted(Response $response, bool $allowRedirect = false): Response
    {
        $this->sensitive($response);
        // The routing pipeline can render an exception before it reaches this
        // transaction. Do not commit e.g. an incomplete refresh rotation.
        if (!$response->isSuccessful() && !($allowRedirect && $response->isRedirection())) {
            throw new HttpResponseException($response);
        }

        return $response;
    }

    private function sensitive(Response $response): Response
    {
        $response->headers->set('Cache-Control', 'no-store');
        $response->headers->set('Referrer-Policy', 'no-referrer');

        return $response;
    }

    private function clientFingerprint(string $id): ?string
    {
        $client = Passport::client()->newQuery()->where('revoked', false)->find($id);

        return $client === null ? null : hash('sha256', serialize($client->getRawOriginal()));
    }
}
