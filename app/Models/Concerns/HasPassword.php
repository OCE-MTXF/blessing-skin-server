<?php

namespace App\Models\Concerns;

use App\Services\Auth\AccountSecurity;
use App\Services\Auth\PasswordCipher;
use Blessing\Filter;
use SensitiveParameter;

trait HasPassword
{
    public function verifyPassword(#[SensitiveParameter] string $raw): bool
    {
        $passed = app(PasswordCipher::class)->verify($raw, $this->password, config('secure.salt'));
        $accepted = app(Filter::class)->apply('verify_password', $passed, [$raw, $this]);

        // Preserve the hook, but do not use it as an alternate authentication path.
        return $passed && (bool) $accepted;
    }

    public function changePassword(#[SensitiveParameter] string $password): bool
    {
        $security = app(AccountSecurity::class);
        if (!$this->exists) {
            $this->password = $security->hashNewPassword($password);

            return $this->save();
        }

        $security->serialized(function () use ($security, $password) {
            $user = $security->lockUser($this->uid);
            $security->assertCurrent($this, $user);
            $security->updatePassword($user, $password);
            $this->setRawAttributes($user->getAttributes(), true);
        });

        return true;
    }
}
