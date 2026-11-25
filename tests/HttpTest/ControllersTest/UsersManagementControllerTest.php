<?php

namespace Tests;

use App\Mail\EmailVerification;
use App\Models\User;
use App\Services\Auth\AccountActions;
use App\Services\Auth\AccountSecurity;
use App\Services\Auth\PasswordCipher;
use App\Services\Option;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;

class UsersManagementControllerTest extends TestCase
{
    use DatabaseTransactions;

    private const ADMIN_PASSWORD = 'Synthetic manager password!';
    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();
        $this->admin = User::factory()->admin()->create(['password' => app(PasswordCipher::class)->hash(self::ADMIN_PASSWORD)]);
        $this->actingAs($this->admin);
    }

    public function testList()
    {
        $user = User::factory()->create();

        $this->getJson(route('admin.users.list'))
            ->assertJson(['data' => [[/* admin is here */], $user->toArray()]]);
    }

    public function testAccessControl()
    {
        // an administrator operating on other administrator should be forbidden
        $otherAdmin = User::factory()->admin()->create();

        $this->putJson(route('admin.users.email', ['user' => $otherAdmin->uid]), ['email' => 'not-changed@example.test'])
            ->assertJson([
                'code' => 1,
                'message' => trans('admin.users.operations.no-permission'),
            ])
            ->assertForbidden();
    }

    public function testEmail()
    {
        $user = User::factory()->create();

        // without `email` field
        $this->putJson(route('admin.users.email', ['user' => $user]))
            ->assertJsonValidationErrors(['email']);

        // with an invalid email address
        $this->putJson(
            route('admin.users.email', ['user' => $user]),
            ['email' => 'invalid']
        )->assertJsonValidationErrors(['email']);

        // use an existed email address
        $other = User::factory()->create();
        $this->putJson(
            route('admin.users.email', ['user' => $user]),
            ['email' => $other->email]
        )->assertJsonValidationErrors(['email']);

        // Managed changes do not transfer the prior email's proof or sessions.
        Mail::fake();
        app(Option::class)->set('require_verification', true);
        $security = app(AccountSecurity::class);
        $security->serialized(fn () => app(AccountActions::class)->issue($security->lockUser($user->uid), AccountActions::RESET));
        Event::fake();
        $this->putJson(
            route('admin.users.email', ['user' => $user]),
            ['email' => 'new@example.test']
        )->assertJson([
            'code' => 0,
            'message' => trans('admin.users.operations.email.success'),
        ]);
        $this->assertDatabaseHas('users', [
            'uid' => $user->uid,
            'email' => 'new@example.test',
            'verified' => false,
            'account_version' => $user->account_version + 1,
        ]);
        $this->assertDatabaseMissing('account_action_tokens', ['user_id' => $user->uid, 'purpose' => AccountActions::RESET]);
        Mail::assertSent(EmailVerification::class, fn (EmailVerification $mail) => $mail->hasTo('new@example.test'));
        Event::assertDispatched('user.email.updating', function ($eventName, $payload) use ($user) {
            $this->assertTrue($user->is($payload[0]));
            $this->assertEquals('new@example.test', $payload[1]);

            return true;
        });
        Event::assertDispatched('user.email.updated', function ($eventName, $payload) use ($user) {
            $this->assertTrue($user->fresh()->is($payload[0]));
            $this->assertEquals($user->email, $payload[1]->email);

            return true;
        });
    }

    public function testVerification()
    {
        app(Option::class)->set('require_verification', true);
        Mail::fake();
        Event::fake();
        $user = User::factory()->create();

        $this->putJson(
            route('admin.users.verification', ['user' => $user])
        )->assertJson([
            'code' => 0,
            'message' => trans('admin.users.operations.verification.success'),
        ]);
        $this->assertDatabaseHas('users', [
            'uid' => $user->uid,
            'verified' => false,
        ]);
        Event::assertDispatched('user.verification.updating', function ($eventName, $payload) use ($user) {
            $this->assertInstanceOf(User::class, $payload[0]);
            $this->assertEquals($user->uid, $payload[0]->uid);

            return true;
        });
        Event::assertDispatched('user.verification.updated', function ($eventName, $payload) {
            $this->assertFalse($payload[0]->verified);

            return true;
        });
        $this->assertSame($user->account_version + 1, $user->fresh()->account_version);
        Mail::assertNothingSent();
        $this->travel(1)->minutes();
        $this->putJson(route('admin.users.verification', ['user' => $user]))
            ->assertOk()->assertJsonPath('code', 0)->assertJsonPath('data.verified', false);
        $this->assertFalse($user->fresh()->verified);
        Mail::assertSent(EmailVerification::class, fn (EmailVerification $mail) => $mail->hasTo($user->email));
    }

    public function testNickname()
    {
        $user = User::factory()->create();

        // without `nickname` field
        $this->putJson(
            route('admin.users.nickname', ['user' => $user])
        )->assertJsonValidationErrors(['nickname']);

        // update successfully
        Event::fake();
        $this->putJson(
            route('admin.users.nickname', ['user' => $user]),
            ['nickname' => 'nickname']
        )->assertJson([
            'code' => 0,
            'message' => trans('admin.users.operations.nickname.success', ['new' => 'nickname']),
        ]);
        $this->assertDatabaseHas('users', [
            'uid' => $user->uid,
            'nickname' => 'nickname',
        ]);
        Event::assertDispatched('user.nickname.updating', function ($eventName, $payload) use ($user) {
            $this->assertTrue($user->is($payload[0]));
            $this->assertEquals('nickname', $payload[1]);

            return true;
        });
        Event::assertDispatched('user.nickname.updated', function ($eventName, $payload) use ($user) {
            $this->assertTrue($user->fresh()->is($payload[0]));
            $this->assertEquals($user->nickname, $payload[1]->nickname);

            return true;
        });
    }

    public function testPassword()
    {
        $user = User::factory()->create();

        // without `password` field
        $this->putJson(
            route('admin.users.password', ['user' => $user])
        )->assertJsonValidationErrors(['password']);

        // too short password
        $this->putJson(
            route('admin.users.password', ['user' => $user]),
            ['password' => '1']
        )->assertJsonValidationErrors(['password']);

        // too long password
        $this->putJson(
            route('admin.users.password', ['user' => $user]),
            ['password' => str_repeat('x', 1025)]
        )->assertJsonValidationErrors(['password']);

        // update successfully
        Event::fake();
        $this->putJson(
            route('admin.users.password', ['user' => $user]),
            ['password' => 'Synthetic managed password!']
        )->assertJson([
            'code' => 0,
            'message' => trans('admin.users.operations.password.success'),
        ]);
        $updated = $user->fresh();
        $this->assertTrue($updated->verifyPassword('Synthetic managed password!'));
        $this->assertSame('argon2id', password_get_info($updated->password)['algoName']);
        $this->assertSame($user->account_version + 1, $updated->account_version);
        Event::assertDispatched('user.password.updating', function ($eventName, $payload) use ($user) {
            $this->assertTrue($user->is($payload[0]));
            $this->assertNull($payload[1]);

            return true;
        });
        Event::assertDispatched('user.password.updated', function ($eventName, $payload) use ($user) {
            $this->assertTrue($user->fresh()->is($payload[0]));

            return true;
        });
    }

    public function testScore()
    {
        $user = User::factory()->create();

        // without `score` field
        $this->putJson(
            route('admin.users.score', ['user' => $user])
        )->assertJsonValidationErrors(['score']);

        // with an non-integer value
        $this->putJson(
            route('admin.users.score', ['user' => $user]),
            ['score' => 'string']
        )->assertJsonValidationErrors(['score']);

        // update successfully
        Event::fake();
        $this->putJson(
            route('admin.users.score', ['user' => $user]),
            ['score' => 123]
        )->assertJson([
            'code' => 0,
            'message' => trans('admin.users.operations.score.success'),
        ]);
        $this->assertDatabaseHas('users', [
            'uid' => $user->uid,
            'score' => 123,
        ]);
        Event::assertDispatched('user.score.updating', function ($eventName, $payload) use ($user) {
            $this->assertTrue($user->is($payload[0]));
            $this->assertEquals(123, $payload[1]);

            return true;
        });
        Event::assertDispatched('user.score.updated', function ($eventName, $payload) use ($user) {
            $this->assertTrue($user->fresh()->is($payload[0]));
            $this->assertEquals($user->score, $payload[1]->score);

            return true;
        });
    }

    public function testPermission()
    {
        $user = User::factory()->create();

        // without `permission` field
        $this->putJson(route('admin.users.permission', ['user' => $user]))
            ->assertJsonValidationErrors(['permission']);

        // invalid permission value
        $this->putJson(
            route('admin.users.permission', ['user' => $user]),
            ['permission' => -2]
        )->assertJsonValidationErrors(['permission']);

        // non-super administrator can't set normal user as administrator
        $this->putJson(
            route('admin.users.permission', ['user' => $user]),
            ['permission' => User::ADMIN]
        )->assertJson([
            'code' => 1,
            'message' => trans('admin.users.operations.no-permission'),
        ])->assertForbidden();

        // administrator can't modify his/her permission
        $this->putJson(
            route('admin.users.permission', ['user' => auth()->user()]),
            ['permission' => User::NORMAL]
        )->assertJson([
            'code' => 1,
            'message' => trans('admin.users.operations.no-permission'),
        ])->assertForbidden();

        // update successfully
        Event::fake();
        $this->putJson(
            route('admin.users.permission', ['user' => $user]),
            ['permission' => User::BANNED]
        )->assertJson([
            'code' => 0,
            'message' => trans('admin.users.operations.permission'),
        ]);
        $this->assertEquals(User::BANNED, $user->fresh()->permission);
        $this->assertSame($user->account_version + 1, $user->fresh()->account_version);
        Event::assertDispatched('user.permission.updating', function ($eventName, $payload) use ($user) {
            $this->assertTrue($user->is($payload[0]));
            $this->assertEquals(User::BANNED, $payload[1]);

            return true;
        });
        Event::assertDispatched('user.permission.updated', function ($eventName, $payload) use ($user) {
            $this->assertTrue($user->fresh()->is($payload[0]));
            $this->assertEquals($user->permission, $payload[1]->permission);

            return true;
        });
        Event::assertDispatched('user.banned', function ($eventName, $payload) use ($user) {
            $this->assertTrue($user->fresh()->is($payload[0]));

            return true;
        });
    }

    public function testDelete()
    {
        Event::fake();
        $user = User::factory()->create();

        Log::spy();
        $params = ['password' => self::ADMIN_PASSWORD, 'confirmation' => 'delete'];
        $url = route('admin.users.delete', ['user' => $user]);
        $this->deleteJson(route('admin.users.delete', ['user' => $this->admin]), $params)->assertForbidden();
        $this->assertModelExists($this->admin);
        $this->deleteJson($url, ['password' => self::ADMIN_PASSWORD])->assertJsonValidationErrors('confirmation');
        $this->deleteJson($url, ['password' => 'incorrect-secret', 'confirmation' => 'delete'])->assertForbidden();
        $this->assertModelExists($user);
        Event::assertNotDispatched('user.deleted');
        $this->deleteJson($url, $params)->assertOk()->assertJson([
            'code' => 0,
            'message' => trans('admin.users.operations.delete.success'),
        ]);
        $this->assertModelMissing($user);
        $this->assertModelExists($this->admin);
        Log::shouldHaveReceived('notice')->with('Managed account removal completed.', [
            'actor_id' => $this->admin->uid,
            'target_id' => $user->uid,
        ])->once();
        Event::assertDispatched('user.deleting', function ($eventName, $payload) use ($user) {
            $this->assertTrue($user->is($payload[0]));

            return true;
        });
        Event::assertDispatched('user.deleted', function ($eventName, $payload) use ($user) {
            $this->assertTrue($user->is($payload[0]));

            return true;
        });
    }
}
