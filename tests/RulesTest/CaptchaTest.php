<?php

namespace Tests\RulesTest;

use App\Rules\Captcha;
use Tests\TestCase;

class CaptchaTest extends TestCase
{
    public function testChallengeIsCaseInsensitiveAndConsumedOnlyOnce(): void
    {
        session(['captcha' => ['phrase' => 'ABC', 'expires_at' => time() + 60]]);
        $rule = new Captcha();

        $this->assertTrue($rule->passes('captcha', 'abc'));
        $this->assertNull(session('captcha'));
        $this->assertFalse($rule->passes('captcha', 'abc'));
    }

    public function testExpiredAndIncorrectChallengesAreRejected(): void
    {
        $rule = new Captcha();
        session(['captcha' => ['phrase' => 'abc', 'expires_at' => time() - 1]]);
        $this->assertFalse($rule->passes('captcha', 'abc'));
        $this->assertNull(session('captcha'));
        session(['captcha' => ['phrase' => 'abc', 'expires_at' => time() + 60]]);
        $this->assertFalse($rule->passes('captcha', 'xyz'));
        $this->assertNull(session('captcha'));
        $this->assertSame(trans('validation.captcha'), $rule->message());
    }
}
