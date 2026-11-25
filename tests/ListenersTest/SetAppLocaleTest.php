<?php

namespace Tests;

use App\Models\User;
use Illuminate\Auth\Events\Authenticated;

class SetAppLocaleTest extends TestCase
{
    public function testExplicitLanguageDoesNotModifyAnAccountOnGet(): void
    {
        $user = User::factory()->create(['locale' => 'zh_CN']);
        $this->get('/?lang=en_US')->assertOk()->assertCookie('locale', 'en', false);
        event(new Authenticated('web', $user));

        $this->assertSame('zh_CN', $user->fresh()->locale);
        $this->assertSame('en', app()->getLocale());
    }

    public function testPersistedLanguageIsRestrictedToTheWebGuardAndSupportedLanguages(): void
    {
        $user = User::factory()->create(['locale' => 'zh_CN']);
        app()->setLocale('en');

        event(new Authenticated('oauth', $user));
        $this->assertSame('en', app()->getLocale());
        event(new Authenticated('web', $user));
        $this->assertSame('zh_CN', app()->getLocale());

        $user->locale = 'not-configured';
        event(new Authenticated('web', $user));
        $this->assertSame('zh_CN', app()->getLocale());
    }
}
