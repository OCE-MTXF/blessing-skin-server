<?php

namespace App\Rules;

use Illuminate\Contracts\Validation\Rule;

final class Captcha implements Rule
{
    public function passes($attribute, $value): bool
    {
        $challenge = session()->pull('captcha');

        return is_array($challenge)
            && is_string($challenge['phrase'] ?? null)
            && $challenge['phrase'] !== ''
            && is_int($challenge['expires_at'] ?? null)
            && $challenge['expires_at'] >= time()
            && is_string($value)
            && strlen($value) <= 32
            && hash_equals(strtolower($challenge['phrase']), strtolower($value));
    }

    public function message(): string
    {
        return trans('validation.captcha');
    }
}
