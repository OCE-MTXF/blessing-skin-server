<?php

namespace Tests;

use App\Models\Texture;
use App\Models\User;
use App\Notifications\SiteMessage;
use App\Services\Auth\AccountSecurity;
use App\Services\Auth\PasswordCipher;
use App\Services\Option;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Storage;
use Laravel\Passport\Client;
use Laravel\Passport\ClientRepository;
use Laravel\Passport\Passport;
use Tests\Support\TextureFixtures;

class OAuthAccountSecurityTest extends TestCase
{
    private const CALLBACK = 'https://client.example.test/callback';
    private const NEW_PASSWORD = 'Synthetic account replacement password!';
    private const OLD_PASSWORD = 'Synthetic account original password!';

    public function testRealPkceAuthorizationAndRefreshThenPasswordChangeRevokesBothGrants(): void
    {
        app(Option::class)->set('require_verification', true);
        $user = User::factory()->create(['password' => app(PasswordCipher::class)->hash(self::OLD_PASSWORD)])->refresh();
        $client = $this->publicClient($user);
        $this->webUser($user);
        [$code, $verifier] = $this->authorize($client);
        $initial = $this->exchange($client, $code, $verifier);
        $this->getJson('/api/user', ['Authorization' => 'Bearer '.$initial['access_token']])->assertOk();
        $refresh = $this->refresh($client, $initial['refresh_token'])->assertOk()->assertJsonStructure(['access_token', 'refresh_token'])->json();
        $this->assertNotSame($initial['access_token'], $refresh['access_token']);
        Auth::forgetGuards();
        $this->getJson('/api/user', ['Authorization' => 'Bearer '.$refresh['access_token']])->assertOk();

        Auth::forgetGuards();
        $this->webUser($user);
        $this->postJson('/user/profile', ['action' => 'password', 'current_password' => self::OLD_PASSWORD, 'new_password' => self::NEW_PASSWORD])
            ->assertOk()->assertJsonPath('code', 0);
        $this->assertGuest('web');
        $this->assertSame($user->account_version + 1, $user->fresh()->account_version);
        $this->assertTrue($user->fresh()->verifyPassword(self::NEW_PASSWORD));
        $this->assertDatabaseMissing('oauth_access_tokens', ['user_id' => $user->uid, 'revoked' => false]);
        $this->assertDatabaseMissing('oauth_refresh_tokens', ['revoked' => false]);
        Auth::forgetGuards();
        $this->getJson('/api/user', ['Authorization' => 'Bearer '.$refresh['access_token']])->assertUnauthorized();
        $this->refresh($client, $refresh['refresh_token'])->assertStatus(400);
    }

    public function testApiRequiresActualBearerAndVerificationPolicyAppliesToOAuth(): void
    {
        app(Option::class)->set('require_verification', false);
        $user = User::factory()->create(['verified' => false])->refresh();
        $client = $this->publicClient($user);
        $this->webUser($user);
        $this->get('/api/user')->assertUnauthorized()->assertHeader('WWW-Authenticate', 'Bearer');
        [$code, $verifier] = $this->authorize($client);
        $token = $this->exchange($client, $code, $verifier);
        $headers = ['Authorization' => 'Bearer '.$token['access_token']];
        $this->get('/api/user', $headers)->assertOk();

        app(Option::class)->set('require_verification', true);
        Auth::forgetGuards();
        $this->get('/api/user', $headers)->assertForbidden();
        $this->refresh($client, $token['refresh_token'])->assertStatus(400);
        $this->assertDatabaseHas('oauth_access_tokens', ['user_id' => $user->uid, 'revoked' => false]);
    }

    public function testAnonymousConsentCanResumeAfterLoginAndUserCanRevokeTheGrant(): void
    {
        $owner = User::factory()->create();
        $this->webUser($owner);
        $clientId = $this->postJson('/oauth/clients', [
            'name' => 'Synthetic public client', 'redirect' => self::CALLBACK, 'confidential' => false,
        ])->assertOk()->assertJsonPath('provider', 'users')->assertJsonMissingPath('plain_secret')->json('id');
        $client = Passport::client()->findOrFail($clientId);
        $user = User::factory()->create(['password' => app(PasswordCipher::class)->hash(self::OLD_PASSWORD)]);
        Auth::forgetGuards();

        [$parameters, $verifier] = $this->authorizationRequest($client);
        $path = '/oauth/authorize?'.http_build_query($parameters);
        $this->get($path)->assertRedirect('/auth/login')->assertSessionHas('last_requested_path', $path);
        $this->postJson('/auth/login', ['identification' => $user->email, 'password' => self::OLD_PASSWORD])
            ->assertOk()->assertJsonPath('code', 0)->assertJsonPath('data.redirectTo', $path);
        $this->get($path)->assertOk()->assertSee('name="auth_token"', false);
        $code = $this->approve($client, $parameters['state']);
        $token = $this->exchange($client, $code, $verifier);
        $this->getJson('/api/user', ['Authorization' => 'Bearer '.$token['access_token']])->assertOk();

        Auth::forgetGuards();
        $id = $this->getJson('/oauth/tokens')->assertOk()->assertJsonCount(1)->json('0.id');
        $this->deleteJson('/oauth/tokens/'.$id)->assertNoContent();
        $this->assertDatabaseHas('oauth_access_tokens', ['id' => $id, 'revoked' => true]);
        $this->assertDatabaseHas('oauth_refresh_tokens', ['access_token_id' => $id, 'revoked' => true]);
        Auth::forgetGuards();
        $this->getJson('/api/user', ['Authorization' => 'Bearer '.$token['access_token']])->assertUnauthorized();
        $this->refresh($client, $token['refresh_token'])->assertBadRequest();
    }

    public function testConsentGrantsOnlyTheRequestedClosetAndNotificationPermissions(): void
    {
        Storage::fake('textures');
        $user = User::factory()->create(['score' => 10000]);
        $texture = Texture::factory()->create(['hash' => TextureFixtures::put(), 'uploader' => $user->uid]);
        $user->notify(new SiteMessage('Synthetic notification', 'Synthetic message'));
        $notification = $user->notifications()->firstOrFail();
        $client = $this->publicClient($user);
        $this->webUser($user);
        [$code, $verifier] = $this->authorize($client, 'Closet.Read Notification.Read');
        $read = ['Authorization' => 'Bearer '.$this->exchange($client, $code, $verifier)['access_token']];
        $ack = '/api/user/notifications/'.$notification->id;
        $item = ['tid' => $texture->tid, 'name' => 'Synthetic fixture'];
        $this->getJson('/api/closet', $read)->assertOk();
        Auth::forgetGuards();
        $this->postJson('/api/closet', $item, $read)->assertForbidden();
        Auth::forgetGuards();
        $this->getJson($ack, $read)->assertOk();
        $this->assertNull($notification->fresh()->read_at);
        Auth::forgetGuards();
        $this->postJson($ack, [], $read)->assertForbidden();

        Auth::forgetGuards();
        $this->webUser($user);
        [$code, $verifier] = $this->authorize($client, 'Closet.ReadWrite Notification.Acknowledge');
        $write = ['Authorization' => 'Bearer '.$this->exchange($client, $code, $verifier)['access_token']];
        $this->postJson('/api/closet', $item, $write)->assertOk()->assertJsonPath('code', 0);
        $this->assertTrue($user->closet()->whereKey($texture->tid)->exists());
        Auth::forgetGuards();
        $this->postJson($ack, [], $write)->assertOk();
        $this->assertNotNull($notification->fresh()->read_at);
        $this->assertNotContains('Closet.ReadWrtie', Passport::scopeIds());
    }

    public function testOAuthClientManagementReturnsAOneTimeSecretAndUsesStrictRedirectPolicy(): void
    {
        $user = User::factory()->create()->refresh();
        $this->webUser($user);
        $this->postJson('/oauth/clients', ['name' => 'Fixture', 'redirect' => 'http://client.example.test/callback'])->assertJsonValidationErrors('redirect');
        $created = $this->postJson('/oauth/clients', ['name' => 'Fixture', 'redirect' => self::CALLBACK])
            ->assertOk()->assertJsonPath('redirect_uris.0', self::CALLBACK)->assertJsonMissingPath('secret')->json();
        $this->assertNotEmpty($created['plain_secret']);
        $client = Passport::client()->findOrFail($created['id']);
        $this->assertTrue(password_verify($created['plain_secret'], $client->secret));
        $this->getJson('/oauth/clients')->assertOk()->assertJsonCount(1)->assertJsonMissingPath('0.plain_secret')->assertJsonMissingPath('0.secret');
        $this->putJson('/oauth/clients/'.$created['id'], ['name' => 'Local fixture', 'redirect' => 'http://127.0.0.1:45678/callback'])
            ->assertOk()->assertJsonPath('redirect_uris.0', 'http://127.0.0.1:45678/callback');
        $this->deleteJson('/oauth/clients/'.$created['id'])->assertNoContent();
        $this->assertTrue($client->fresh()->revoked);
        $this->assertFalse(app('router')->getRoutes()->hasNamedRoute('passport.transient.refresh'));
    }

    public function testLoopbackConsentUsesAnUncacheableLinkAndRetainsPkce(): void
    {
        $user = User::factory()->create()->refresh();
        $this->webUser($user);
        $registered = 'http://127.0.0.1:41630/callback?mode=desktop';
        $client = $this->publicClient($user, $registered);
        [$parameters, $verifier] = $this->authorizationRequest($client);
        $callback = 'http://127.0.0.1:45678/callback?mode=desktop';
        $parameters['redirect_uri'] = $callback;
        $this->get('/oauth/authorize?'.http_build_query($parameters))->assertOk();
        $handoff = $this->post('/oauth/authorize', ['auth_token' => session('authToken')])
            ->assertOk()->assertHeaderMissing('Location')->assertHeader('Referrer-Policy', 'no-referrer')
            ->assertHeader('X-Robots-Tag', 'noindex, nofollow')->assertSee('id="oauth-return"', false)
            ->assertDontSee('<script', false)->assertSee('rel="noreferrer"', false);
        $this->assertStringContainsString('no-store', $handoff->headers->get('Cache-Control'));
        $this->assertStringNotContainsString('127.0.0.1', $handoff->headers->get('Content-Security-Policy'));
        $query = $this->readHandoff($handoff->getContent(), $callback.'&');
        $this->assertSame('desktop', $query['mode']);
        $this->assertSame($parameters['state'], $query['state']);
        $this->assertArrayHasKey('code', $query);
        $this->exchange($client, $query['code'], $verifier, $callback);
    }

    public function testLoopbackDenialReturnsStateWithoutAnAuthorizationCode(): void
    {
        $user = User::factory()->create()->refresh();
        $this->webUser($user);
        $callback = 'http://[::1]:45678/callback';
        $client = $this->publicClient($user, $callback);
        [$parameters] = $this->authorizationRequest($client);
        $parameters['redirect_uri'] = $callback;
        $this->get('/oauth/authorize?'.http_build_query($parameters))->assertOk();
        $authToken = session('authToken');
        $handoff = $this->delete('/oauth/authorize', ['auth_token' => $authToken])
            ->assertOk()->assertHeaderMissing('Location');
        $query = $this->readHandoff($handoff->getContent(), $callback.'?');
        $this->assertSame($parameters['state'], $query['state']);
        $this->assertSame('access_denied', $query['error']);
        $this->assertArrayNotHasKey('code', $query);
        $this->delete('/oauth/authorize', ['auth_token' => $authToken])->assertForbidden();
    }

    private function readHandoff(string $html, string $prefix): array
    {
        $document = \Dom\HTMLDocument::createFromString($html);
        $link = $document->getElementById('oauth-return');
        $this->assertNotNull($link);
        $uri = $link->getAttribute('href');
        $this->assertTrue(str_starts_with($uri, $prefix));
        parse_str(parse_url($uri, PHP_URL_QUERY), $query);

        return $query;
    }

    private function webUser(User $user): void
    {
        $this->actingAs($user, 'web')->withSession([AccountSecurity::SESSION_VERSION => $user->account_version]);
    }

    private function publicClient(User $user, string $callback = self::CALLBACK): Client
    {
        return app(ClientRepository::class)->createAuthorizationCodeGrantClient('Synthetic PKCE client', [$callback], false, $user);
    }

    private function authorizationRequest(Client $client, string $scope = 'User.Read'): array
    {
        $verifier = bin2hex(random_bytes(32));
        $challenge = rtrim(strtr(base64_encode(hash('sha256', $verifier, true)), '+/', '-_'), '=');
        $parameters = [
            'client_id' => $client->getKey(), 'redirect_uri' => self::CALLBACK, 'response_type' => 'code',
            'scope' => $scope, 'state' => 'local-test-state', 'code_challenge' => $challenge, 'code_challenge_method' => 'S256',
        ];

        return [$parameters, $verifier];
    }

    private function authorize(Client $client, string $scope = 'User.Read'): array
    {
        [$parameters, $verifier] = $this->authorizationRequest($client, $scope);
        $this->get('/oauth/authorize?'.http_build_query($parameters))->assertOk()->assertSee('name="auth_token"', false);

        return [$this->approve($client, $parameters['state']), $verifier];
    }

    private function approve(Client $client, string $state): string
    {
        $approved = $this->post('/oauth/authorize', [
            'client_id' => $client->getKey(), 'state' => $state, 'auth_token' => session('authToken'),
        ])->assertRedirect();
        $location = $approved->headers->get('Location');
        $this->assertStringStartsWith(self::CALLBACK.'?', $location);
        parse_str(parse_url($location, PHP_URL_QUERY), $query);
        $this->assertSame($state, $query['state']);
        $this->assertArrayHasKey('code', $query);

        return $query['code'];
    }

    private function exchange(Client $client, string $code, string $verifier, string $callback = self::CALLBACK): array
    {
        return $this->post('/oauth/token', [
            'grant_type' => 'authorization_code', 'client_id' => (string) $client->getKey(), 'redirect_uri' => $callback,
            'code' => $code, 'code_verifier' => $verifier,
        ])->assertOk()->assertJsonStructure(['access_token', 'refresh_token'])->json();
    }

    private function refresh(Client $client, string $refreshToken)
    {
        return $this->post('/oauth/token', [
            'grant_type' => 'refresh_token', 'client_id' => (string) $client->getKey(), 'refresh_token' => $refreshToken,
        ]);
    }
}
