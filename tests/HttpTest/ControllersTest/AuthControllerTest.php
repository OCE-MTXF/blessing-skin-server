<?php

namespace Tests;

use App\Events\UserRegistered;
use App\Mail\EmailVerification;
use App\Mail\ForgotPassword;
use App\Models\Player;
use App\Models\User;
use App\Services\Auth\AccountActions;
use App\Services\Auth\AccountIdentity;
use App\Services\Auth\AccountMail;
use App\Services\Auth\AccountSecurity;
use App\Services\Auth\PasswordCipher;
use App\Services\Auth\SendAccountMail;
use App\Services\Option;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Queue;

class AuthControllerTest extends TestCase
{
    private const SECRET = 'Example local passphrase 123!';

    public function testLoginHasUniformFailuresAndPromotesALegacyHashOnlyOnSuccess(): void
    {
        $legacy = password_hash(self::SECRET, PASSWORD_BCRYPT);
        $user = User::factory()->create(['email' => 'member@example.test', 'password' => $legacy])->refresh();
        $player = Player::factory()->create(['uid' => $user->uid]);
        $this->get('/auth/login')->assertOk();
        $failures = [];
        foreach (['member@example.test', 'unregistered@example.test'] as $index => $identity) {
            $failures[] = $this->withServerVariables(['REMOTE_ADDR' => '192.0.2.'.($index + 1)])
                ->postJson('/auth/login', ['identification' => $identity, 'password' => 'incorrect'])
                ->assertOk()->assertJsonPath('code', 1)->json();
        }
        $this->assertSame($failures[0], $failures[1]);
        $this->assertSame($legacy, $user->fresh()->password);

        $this->postJson('/auth/login', ['identification' => $player->name, 'password' => self::SECRET, 'keep' => true])
            ->assertOk()->assertJsonPath('code', 0)
            ->assertSessionHas(AccountSecurity::SESSION_VERSION, $user->account_version);
        $this->assertAuthenticatedAs($user);
        $this->assertSame('argon2id', password_get_info($user->fresh()->password)['algoName']);
        $this->assertNotEmpty($user->fresh()->getRememberToken());
        $this->postJson('/auth/logout')->assertOk()->assertJsonPath('code', 0);
        $this->assertGuest();
        $this->assertNull($user->fresh()->remember_token);
        $this->assertSame($user->account_version + 1, $user->fresh()->account_version);
    }

    public function testCaptchaIsAnExpiringSingleUseJpegChallengeAndLoginIsBounded(): void
    {
        $this->get('/auth/captcha')->assertOk()->assertHeader('Content-Type', 'image/jpeg')->assertSessionHas('captcha', function ($challenge) {
            return is_string($challenge['phrase']) && $challenge['expires_at'] > time();
        });
        for ($i = 0; $i < 4; $i++) {
            $this->postJson('/auth/login', ['identification' => 'fixture@example.test', 'password' => 'incorrect'])->assertJsonPath('code', 1);
        }
        $this->withSession(['captcha' => ['phrase' => 'AbCd', 'expires_at' => time() - 1]])
            ->postJson('/auth/login', ['identification' => 'fixture@example.test', 'password' => 'incorrect', 'captcha' => 'abcd'])
            ->assertUnprocessable()->assertJsonValidationErrors('captcha')->assertSessionMissing('captcha');
        $this->captcha()->postJson('/auth/login', ['identification' => 'fixture@example.test', 'password' => 'incorrect', 'captcha' => 'abcd'])
            ->assertOk()->assertJsonPath('code', 1)->assertSessionMissing('captcha');
        for ($i = 0; $i < 18; $i++) {
            $this->postJson('/auth/login', ['identification' => 'fixture@example.test', 'password' => 'incorrect'])->assertUnprocessable();
        }
        $this->postJson('/auth/login', ['identification' => 'fixture@example.test', 'password' => 'incorrect'])
            ->assertTooManyRequests()->assertHeader('Retry-After');
    }

    public function testRegistrationCommitsPlayerAndAccountTogetherWithUniqueIdentityAndQuota(): void
    {
        app(Option::class)->set(['regs_per_ip' => 1, 'register_with_player_name' => true, 'require_verification' => true]);
        Mail::fake();
        Event::listen(UserRegistered::class, function (UserRegistered $event): void {
            $this->assertTrue($event->user->players()->exists());
        });
        $this->get('/auth/register')->assertOk();
        $data = ['email' => 'Member@example.test', 'player_name' => 'LocalFixture', 'password' => self::SECRET, 'captcha' => 'abcd'];
        $this->captcha()->postJson('/auth/register', $data)->assertOk()->assertJsonPath('code', 0);
        $user = User::where('email', 'member@example.test')->sole();
        $this->assertDatabaseHas('players', ['uid' => $user->uid, 'name' => 'LocalFixture']);
        $this->assertFalse($user->verified);
        Mail::assertSent(EmailVerification::class, fn (EmailVerification $mail) => $mail->hasTo($user->email));
        $this->postJson('/auth/logout')->assertOk();
        Auth::forgetGuards();

        $this->captcha()->postJson('/auth/register', array_replace($data, ['email' => 'another@example.test', 'player_name' => 'AnotherFixture']))
            ->assertOk()->assertJsonPath('code', 1);
        $this->withServerVariables(['REMOTE_ADDR' => '192.0.2.29']);
        $this->captcha()->postJson('/auth/register', array_replace($data, ['player_name' => 'OtherFixture']))
            ->assertUnprocessable()->assertJsonValidationErrors('email');
        $this->captcha()->postJson('/auth/register', array_replace($data, ['email' => 'another@example.test']))
            ->assertOk()->assertJsonPath('code', 1);
        $this->assertDatabaseCount('users', 1);
        $this->assertDatabaseCount('players', 1);
    }

    public function testPasswordResetMailUsesCanonicalOriginAndStoredAddressAndChangesStateOnce(): void
    {
        Mail::fake();
        $user = User::factory()->create(['email' => 'owner@example.test', 'remember_token' => str_repeat('a', 60)])->refresh();
        // A legacy stored mailbox may preserve case. Lookups use the canonical key;
        // delivery always uses the value stored for the matched user.
        DB::table('users')->where('uid', $user->uid)->update(['email' => 'Owner@example.test']);
        config(['app.url' => 'https://localhost']);
        $this->get('https://localhost/auth/forgot')->assertOk();
        $this->captcha()->postJson('https://localhost/auth/forgot', ['email' => 'OWNER@example.test', 'captcha' => 'abcd'])->assertOk()->assertJsonPath('code', 0);
        $url = null;
        Mail::assertSent(ForgotPassword::class, function (ForgotPassword $mail) use (&$url) {
            $url = $mail->url;

            return $mail->hasTo('Owner@example.test');
        });
        $this->assertStringStartsWith('https://localhost/auth/reset/', $url);
        parse_str((string) parse_url($url, PHP_URL_QUERY), $parameters);
        $this->assertDatabaseHas('account_action_tokens', ['digest' => hash('sha256', $parameters['token']), 'user_id' => $user->uid]);
        $this->get($url)->assertOk()->assertHeader('Referrer-Policy', 'no-referrer');
        $this->postJson($url, ['password' => self::SECRET])->assertOk()->assertJsonPath('code', 0);
        $this->assertTrue($user->fresh()->verifyPassword(self::SECRET));
        $this->assertSame($user->account_version + 1, $user->fresh()->account_version);
        $this->assertNull($user->fresh()->remember_token);
        $this->assertDatabaseMissing('account_action_tokens', ['user_id' => $user->uid, 'purpose' => AccountActions::RESET]);

        $this->captcha()->postJson('https://localhost/auth/forgot', ['email' => 'absent@example.test', 'captcha' => 'abcd'])
            ->assertOk()->assertExactJson(['code' => 0, 'message' => trans('auth.forgot.success')]);
        Mail::assertSentCount(1);
    }

    public function testAccountMailRejectsSynchronousOrLoggingProductionConfiguration(): void
    {
        $this->app->instance('env', 'production');
        config(['app.url' => 'https://localhost', 'mail.default' => 'smtp', 'queue.default' => 'sync']);
        User::factory()->create(['email' => 'known@example.test']);
        Mail::fake();
        $mail = app(AccountMail::class);
        $this->assertFalse($mail->enabled());
        $csrf = bin2hex(random_bytes(20));
        foreach (['known@example.test', 'absent@example.test'] as $email) {
            $this->captcha()->withSession(['_token' => $csrf])->postJson('https://localhost/auth/forgot', [
                'email' => $email, 'captcha' => 'abcd', '_token' => $csrf,
            ])->assertOk()->assertExactJson(['code' => 0, 'message' => trans('auth.forgot.success')]);
        }
        Mail::assertNothingSent();
        $this->assertDatabaseCount('jobs', 0);
        $this->assertDatabaseCount('account_action_tokens', 0);
        config(['queue.default' => 'database']);
        $this->assertTrue($mail->enabled());
        foreach (['log', 'failover', 'array'] as $transport) {
            config(['mail.default' => $transport]);
            $this->assertFalse($mail->enabled());
        }
    }

    public function testRecoveryUsesEncryptedDatabaseQueueAndDiscardsStaleWork(): void
    {
        config(['queue.default' => 'database', 'queue.connections.database.connection' => 'sqlite']);
        Mail::fake();
        $user = User::factory()->create(['email' => 'known@example.test']);
        $responses = [];
        foreach ([$user->email, 'absent@example.test'] as $email) {
            $responses[] = $this->captcha()->postJson('/auth/forgot', ['email' => $email, 'captcha' => 'abcd'])->assertOk()->json();
        }
        $this->assertSame($responses[0], $responses[1]);
        Mail::assertNothingSent();
        $this->assertDatabaseCount('account_action_tokens', 0);
        $this->assertDatabaseCount('jobs', 2);
        $payload = json_decode(DB::table('jobs')->orderBy('id')->value('payload'), true, flags: JSON_THROW_ON_ERROR);
        $this->assertTrue($payload['failOnTimeout']);
        $this->assertSame(25, $payload['timeout']);
        $this->assertSame(1, $payload['maxTries']);
        $this->assertStringNotContainsString($user->email, $payload['data']['command']);
        $serialized = app('encrypter')->decrypt($payload['data']['command']);
        $job = unserialize($serialized, ['allowed_classes' => [SendAccountMail::class]]);
        $this->assertInstanceOf(SendAccountMail::class, $job);
        $this->assertSame(AccountIdentity::emailKey($user->email), $job->subject);
        $this->assertStringNotContainsString($user->email, $serialized);
        $queue = Queue::connection('database');
        $queue->pop()->fire();
        $queue->pop()->fire();
        $this->assertDatabaseCount('jobs', 0);
        $this->assertDatabaseCount('account_action_tokens', 1);
        Mail::assertSent(ForgotPassword::class, fn (ForgotPassword $mail) => $mail->hasTo($user->email));

        app(AccountMail::class)->reset($user->email);
        $this->assertDatabaseCount('jobs', 1);
        $digest = DB::table('account_action_tokens')->value('digest');
        $this->travel(16)->minutes();
        $queue->pop()->fire();
        $this->assertDatabaseCount('jobs', 0);
        $this->assertDatabaseCount('failed_jobs', 0);
        $this->assertSame($digest, DB::table('account_action_tokens')->value('digest'));
        Mail::assertSentCount(1);
    }

    public function testVerifyGetDoesNotChangeStateAndPostConsumesProof(): void
    {
        app(Option::class)->set('require_verification', true);
        $user = User::factory()->create(['verified' => false])->refresh();
        $url = $this->issueFor($user, AccountActions::VERIFY);
        $path = $this->path($url);
        $this->get($path)->assertOk();
        $this->assertFalse($user->fresh()->verified);
        $this->post($path)->assertRedirect(route('user.home'));
        $this->assertTrue($user->fresh()->verified);
        $this->assertNotNull(DB::table('account_action_tokens')->where('user_id', $user->uid)->value('consumed_at'));
    }

    public function testExpiredResetLinkIsDeniedWithoutChangingAccount(): void
    {
        $user = User::factory()->create()->refresh();
        $path = $this->path($this->issueFor($user, AccountActions::RESET));
        $this->travel(31)->minutes();
        $this->get($path)->assertForbidden();
        $this->assertSame($user->account_version, $user->fresh()->account_version);
    }

    public function testBindingRequiresCurrentPasswordAndClearsImportedVerification(): void
    {
        app(Option::class)->set('require_verification', true);
        Mail::fake();
        $user = User::factory()->create(['email' => '', 'password' => app(PasswordCipher::class)->hash(self::SECRET)])->refresh();
        $this->actingAs($user)->withSession([AccountSecurity::SESSION_VERSION => $user->account_version]);
        $this->get('/auth/bind')->assertOk();
        $this->postJson('/auth/bind', ['email' => 'bound@example.test', 'password' => 'wrong-secret'])->assertJsonValidationErrors('password');
        $this->post('/auth/bind', ['email' => 'bound@example.test', 'password' => self::SECRET])->assertRedirect(route('auth.login'));
        $this->assertSame('bound@example.test', $user->fresh()->email);
        $this->assertFalse($user->fresh()->verified);
        $this->assertSame($user->account_version + 1, $user->fresh()->account_version);
        Mail::assertSent(EmailVerification::class, fn (EmailVerification $mail) => $mail->hasTo('bound@example.test'));
        $this->assertGuest();
    }

    private function captcha(): self
    {
        return $this->withSession(['captcha' => ['phrase' => 'AbCd', 'expires_at' => time() + 300]]);
    }

    private function issueFor(User $user, string $purpose): string
    {
        $security = app(AccountSecurity::class);

        return $security->serialized(fn () => app(AccountActions::class)->issue($security->lockUser($user->uid), $purpose));
    }

    private function path(string $url): string
    {
        return parse_url($url, PHP_URL_PATH).'?'.parse_url($url, PHP_URL_QUERY);
    }
}
