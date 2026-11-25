<?php

namespace Tests;

use App\Events\UserProfileUpdated;
use App\Mail\EmailVerification;
use App\Models\Player;
use App\Models\Texture;
use App\Models\User;
use App\Services\Auth\AccountActions;
use App\Services\Auth\AccountSecurity;
use App\Services\Auth\PasswordCipher;
use App\Services\Auth\SendAccountMail;
use App\Services\Option;
use Blessing\Filter;
use Blessing\Rejection;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Storage;
use Tests\Support\TextureFixtures;

class UserControllerTest extends TestCase
{
    private const PASSWORD = 'Synthetic profile original password!';

    public function testDashboardProfileAndScoreInfo(): void
    {
        $filter = Fakes\Filter::fake();
        $user = User::factory()->create(['verified' => false]);
        Player::factory()->create(['uid' => $user->uid]);

        $this->actingAs($user)->get('/user')->assertOk()->assertViewIs('user.index');
        $filter->assertApplied('grid:user.index');
        $this->get('/user/profile')->assertOk()->assertViewIs('user.profile');
        $filter->assertApplied('grid:user.profile');
        $this->getJson('/user/score-info')->assertOk()->assertJson([
            'user' => ['score' => $user->score, 'lastSignAt' => $user->last_sign_at],
            'usage' => ['players' => 1, 'storage' => 0],
        ]);
    }

    public function testSignAwardIsCommittedOnceAndCanBeVetoed(): void
    {
        $user = User::factory()->create(['score' => 100, 'last_sign_at' => CarbonImmutable::now()->subHours(3)]);
        app(Option::class)->set(['sign_score' => '50,50', 'sign_gap_time' => 2, 'sign_after_zero' => false]);
        Event::listen('user.sign.after', function ($score) use ($user) {
            $this->assertSame(50, $score);
            $this->assertSame(150, $user->fresh()->score);
        });
        $this->actingAs($user)->postJson('/user/sign')->assertOk()->assertJsonPath('code', 0)->assertJsonPath('data.score', 150);
        $this->postJson('/user/sign')->assertOk()->assertJsonPath('code', 1);
        app(Filter::class)->add('can_sign', fn () => new Rejection('fixture-no-sign'));
        $this->postJson('/user/sign')->assertOk()->assertJsonPath('code', 2)->assertJsonPath('message', 'fixture-no-sign');
        $this->assertSame(150, $user->fresh()->score);
    }

    public function testCalendarSignUsesTheStartOfDayAsACutoff(): void
    {
        app(Option::class)->set(['sign_after_zero' => true, 'sign_score' => '5,5']);
        $now = CarbonImmutable::parse('2026-01-04 01:00:00', config('app.timezone'));
        $this->travelTo($now);
        $alreadySigned = User::factory()->create(['last_sign_at' => $now->startOfDay()]);
        $this->actingAs($alreadySigned)->postJson('/user/sign')->assertOk()->assertJsonPath('code', 1);
        $yesterday = User::factory()->create(['last_sign_at' => $now->startOfDay()->subSecond()]);
        $this->actingAs($yesterday)->postJson('/user/sign')->assertOk()->assertJsonPath('code', 0);
        $this->travelBack();
    }

    public function testVerificationCooldownUsesTheAccountNotTheBrowserSession(): void
    {
        Mail::fake();
        $user = User::factory()->create(['verified' => false]);
        $this->actingAs($user);
        app(Option::class)->set('require_verification', false);
        $this->postJson('/user/email-verification')->assertOk()->assertJsonPath('message', trans('user.verification.disabled'));
        Mail::assertNothingSent();
        app(Option::class)->set('require_verification', true);
        $this->postJson('/user/email-verification')->assertOk()->assertJsonPath('code', 0);
        Mail::assertSent(EmailVerification::class, fn (EmailVerification $mail) => $mail->hasTo($user->email));
        $this->flushSession();
        $this->actingAs($user)->withServerVariables(['REMOTE_ADDR' => '192.0.2.127'])
            ->postJson('/user/email-verification')->assertTooManyRequests()->assertHeader('Retry-After');
        Mail::assertSentCount(1);
    }

    public function testVerificationDoesNotExposeTransportFailure(): void
    {
        app(Option::class)->set('require_verification', true);
        Mail::shouldReceive('to')->once()->andThrow(new \RuntimeException('synthetic-private-transport-detail'));
        $this->actingAs(User::factory()->create(['verified' => false]))->postJson('/user/email-verification')
            ->assertOk()->assertJsonPath('code', 0)->assertDontSee('synthetic-private-transport-detail');
    }

    public function testProfileNicknameValidationAndAfterCommitEvent(): void
    {
        Event::fake([UserProfileUpdated::class]);
        $user = User::factory()->create();
        $this->actingAs($user)->postJson('/user/profile', ['action' => 'nickname'])->assertJsonValidationErrors('new_nickname');
        $this->postJson('/user/profile', ['action' => 'nickname', 'new_nickname' => 'Fixture name'])->assertJsonPath('code', 0);
        $this->assertSame('Fixture name', $user->fresh()->nickname);
        Event::assertDispatched(UserProfileUpdated::class);
        $this->putJson('/user/dark-mode')->assertNoContent();
        $this->assertTrue($user->fresh()->is_dark_mode);
    }

    public function testEmailChangeSendsOldAddressWarningAndNewAddressProofAfterCommit(): void
    {
        Bus::fake([SendAccountMail::class]);
        app(Option::class)->set('require_verification', true);
        $user = User::factory()->create([
            'email' => 'old@example.test', 'password' => app(PasswordCipher::class)->hash(self::PASSWORD),
            'remember_token' => str_repeat('a', 60),
        ]);
        $this->actingAs($user)->postJson('/user/profile', ['action' => 'email', 'email' => 'new@example.test', 'password' => 'incorrect-secret'])
            ->assertOk()->assertJsonPath('code', 1);
        Bus::assertNotDispatched(SendAccountMail::class);
        $this->postJson('/user/profile', ['action' => 'email', 'email' => 'NEW@example.test', 'password' => self::PASSWORD])
            ->assertOk()->assertJsonPath('code', 0)->assertSessionMissing(AccountSecurity::SESSION_VERSION);
        $this->assertGuest('web');
        $new = $user->fresh();
        $this->assertSame('new@example.test', $new->email);
        $this->assertFalse($new->verified);
        $this->assertNull($new->remember_token);
        $this->assertSame($user->account_version + 1, $new->account_version);
        Bus::assertDispatched(SendAccountMail::class, fn (SendAccountMail $job) => $job->kind === 'address_changed' && $job->subject === $user->email);
        Bus::assertDispatched(SendAccountMail::class, fn (SendAccountMail $job) => $job->kind === AccountActions::VERIFY
            && $job->subject === $user->uid && $job->version === $new->account_version);
    }

    public function testRegularAccountCanBeDeletedAfterReauthenticationButAdminCannotSelfDelete(): void
    {
        $password = app(PasswordCipher::class)->hash(self::PASSWORD);
        $admin = User::factory()->admin()->create(['password' => $password]);
        $this->actingAs($admin)->postJson('/user/profile', ['action' => 'delete', 'password' => self::PASSWORD])
            ->assertOk()->assertJsonPath('code', 1);
        $this->assertModelExists($admin);
        $user = User::factory()->create(['password' => $password]);
        $player = Player::factory()->create(['uid' => $user->uid]);
        $this->actingAs($user)->postJson('/user/profile', ['action' => 'delete', 'password' => 'incorrect-secret'])->assertJsonPath('code', 1);
        $this->assertModelExists($user);
        $this->postJson('/user/profile', ['action' => 'delete', 'password' => self::PASSWORD])->assertOk()->assertJsonPath('code', 0);
        $this->assertGuest('web');
        $this->assertModelMissing($user);
        $this->assertModelMissing($player);
    }

    public function testAvatarRequiresPublishedUsableClosetSkinAndCanBeCleared(): void
    {
        Storage::fake('textures');
        $user = User::factory()->create();
        $uploader = User::factory()->create();
        $texture = Texture::factory()->create(['hash' => TextureFixtures::put(), 'uploader' => $uploader->uid]);
        $cape = Texture::factory()->cape()->create(['uploader' => $uploader->uid]);
        $private = Texture::factory()->private()->create(['uploader' => $uploader->uid]);
        $this->actingAs($user)->postJson('/user/profile/avatar', ['tid' => -1])->assertJsonValidationErrors('tid');
        $this->postJson('/user/profile/avatar', ['tid' => 87654])->assertJsonPath('message', trans('skinlib.non-existent'));
        $this->postJson('/user/profile/avatar', ['tid' => $cape->tid])->assertJsonPath('message', trans('user.profile.avatar.wrong-type'));
        $this->postJson('/user/profile/avatar', ['tid' => $private->tid])->assertJsonPath('code', 1);
        $this->postJson('/user/profile/avatar', ['tid' => $texture->tid])->assertJsonPath('code', 1);
        $user->closet()->attach($texture->tid, ['item_name' => 'Fixture']);
        $this->postJson('/user/profile/avatar', ['tid' => $texture->tid])->assertJsonPath('code', 0);
        $this->assertSame($texture->tid, $user->fresh()->avatar);
        $this->postJson('/user/profile/avatar', ['tid' => 0])->assertJsonPath('code', 0);
        $this->assertSame(0, $user->fresh()->avatar);
    }
}
