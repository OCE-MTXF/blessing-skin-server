<?php

namespace App\Services\Auth;

use App\Services\Cipher;
use SensitiveParameter;

/** Modern writes with a constrained, verify-only path for existing password stores. */
final class PasswordCipher extends Cipher\BaseCipher
{
    public const NEW_RULE = 'required|string|min:8|max:1024';
    public const CURRENT_RULE = 'required|string|max:1024';

    private const OPTIONS = ['memory_cost' => 65536, 'time_cost' => 3, 'threads' => 1];
    // Hash of a discarded random value, not a credential.
    private const TIMING_HASH = '$argon2id$v=19$m=65536,t=3,p=1$b1kzMWxnL2l4aWRaTTd2OQ$bP+r/uVKb8lKeXpkfaQO4z1PcvIFGu4BBqohYZ8l2Rw';
    private const LEGACY_DRIVERS = [
        'MD5' => Cipher\MD5::class,
        'SALTED2MD5' => Cipher\SALTED2MD5::class,
        'SHA256' => Cipher\SHA256::class,
        'SALTED2SHA256' => Cipher\SALTED2SHA256::class,
        'SHA512' => Cipher\SHA512::class,
        'SALTED2SHA512' => Cipher\SALTED2SHA512::class,
    ];

    public function hash(#[SensitiveParameter] $value, #[SensitiveParameter] $salt = ''): string
    {
        return password_hash($value, PASSWORD_ARGON2ID, self::OPTIONS);
    }

    public function verify(#[SensitiveParameter] $password, #[SensitiveParameter] $hash, #[SensitiveParameter] $salt = ''): bool
    {
        if (!is_string($hash)) {
            $this->dummyVerify($password);

            return false;
        }

        $algorithm = password_get_info($hash)['algoName'];
        if (in_array($algorithm, ['argon2i', 'argon2id', 'bcrypt'], true)) {
            // Bcrypt would silently disregard trailing bytes past its input limit.
            $matched = ($algorithm !== 'bcrypt' || strlen($password) <= 72) && password_verify($password, $hash);
            if ($algorithm === 'bcrypt' && !$matched) {
                $this->dummyVerify($password);
            }

            return $matched;
        }

        $this->dummyVerify($password);
        $class = self::LEGACY_DRIVERS[config('secure.cipher')] ?? null;

        return $class !== null && (new $class())->verify($password, $hash, $salt);
    }

    public function dummyVerify(#[SensitiveParameter] string $password): void
    {
        password_verify($password, self::TIMING_HASH);
    }

    public function needsRehash(#[SensitiveParameter] string $hash): bool
    {
        return password_needs_rehash($hash, PASSWORD_ARGON2ID, self::OPTIONS);
    }
}
