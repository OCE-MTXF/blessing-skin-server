<?php

namespace Tests;

use App\Models\User;
use App\Services\Auth\AccountSecurity;
use Illuminate\Support\Facades\Auth;

class EnforceAccountVersionTest extends TestCase
{
    public function testSessionWithNoVersionRequiresNewAuthentication(): void
    {
        $user = User::factory()->create();
        $guard = Auth::guard('web');
        $guard->setUser($user);
        $this->withSession([$guard->getName() => $user->uid]);
        $this->get('/user')->assertRedirect('/auth/login');
        $this->assertGuest('web');
        $this->assertNull(session(AccountSecurity::SESSION_VERSION));
    }

    public function testAnAccountInvalidationRejectsAnotherSessionWithoutRotatingNewCredentials(): void
    {
        $user = User::factory()->create(['remember_token' => str_repeat('X', 60)]);
        $security = app(AccountSecurity::class);
        $security->serialized(fn () => $security->invalidate($security->lockUser($user->uid)));
        $current = $user->fresh();
        $current->remember_token = str_repeat('Y', 60);
        $current->save();

        $this->actingAs($current)->withSession([AccountSecurity::SESSION_VERSION => $user->account_version]);
        $this->get('/user')->assertRedirect('/auth/login')->assertSessionMissing(AccountSecurity::SESSION_VERSION);
        $this->assertGuest('web');
        $this->assertSame(str_repeat('Y', 60), $user->fresh()->remember_token);
        $this->actingAs($current)->get('/user')->assertOk();
    }
}
