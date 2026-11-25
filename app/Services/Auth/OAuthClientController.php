<?php

namespace App\Services\Auth;

use Illuminate\Http\Request;
use Laravel\Passport\Client;
use Laravel\Passport\ClientRepository;
use Laravel\Passport\Passport;
use RuntimeException;

final class OAuthClientController
{
    public function __construct(private ClientRepository $clients)
    {
    }

    public function index(Request $request)
    {
        return $this->clients->forUser($request->user());
    }

    public function store(Request $request)
    {
        [$name, $redirects] = $this->validateClient($request);
        $request->validate(['confidential' => 'sometimes|boolean']);
        $confidential = $request->boolean('confidential', true);
        $client = $this->clients->createAuthorizationCodeGrantClient($name, $redirects, $confidential, $request->user());
        // Passport's convenience factory leaves the provider null. This app's
        // only Passport user provider is users (the guard is named oauth).
        if (!$client->exists || !$client->forceFill(['provider' => 'users'])->save()) {
            throw new RuntimeException('OAuth client creation was cancelled.');
        }

        return response()->json($confidential ? $client->append('plain_secret') : $client);
    }

    public function update(Request $request, string $client_id)
    {
        $client = $this->ownedClient($request, $client_id);
        [$name, $redirects] = $this->validateClient($request);
        if ($client->redirect_uris !== $redirects) {
            $this->revokeGrants($client);
        }
        if (!$this->clients->update($client, $name, $redirects)) {
            throw new RuntimeException('OAuth client update was cancelled.');
        }

        return $client;
    }

    public function destroy(Request $request, string $client_id)
    {
        $client = $this->ownedClient($request, $client_id);
        $this->revokeGrants($client);
        if (!$client->forceFill(['revoked' => true])->save()) {
            throw new RuntimeException('OAuth client revocation was cancelled.');
        }

        return response()->noContent();
    }

    private function ownedClient(Request $request, string $id): Client
    {
        return $request->user()->oauthApps()->where('revoked', false)->findOrFail($id);
    }

    private function revokeGrants(Client $client): void
    {
        $tokens = Passport::token()->newQuery()->where('client_id', $client->getKey());
        Passport::refreshToken()->newQuery()->whereIn('access_token_id', (clone $tokens)->select('id'))->update(['revoked' => true]);
        $tokens->update(['revoked' => true]);
        Passport::authCode()->newQuery()->where('client_id', $client->getKey())->update(['revoked' => true]);
    }

    private function validateClient(Request $request): array
    {
        $data = $request->validate([
            'name' => 'required|string|max:255',
            'redirect' => ['bail', 'required', 'string', 'max:4096', static function ($attribute, $value, $fail) {
                $redirects = explode(',', $value);
                if (count($redirects) > 10) {
                    $fail('Too many redirect URIs.');

                    return;
                }
                foreach ($redirects as $uri) {
                    if (OAuthRedirectUri::allowedScheme($uri) === null) {
                        $fail('Redirect URIs must use HTTPS or an HTTP loopback address and cannot contain credentials or fragments.');

                        return;
                    }
                }
            }],
        ]);

        return [$data['name'], array_values(array_unique(explode(',', $data['redirect'])))];
    }
}
