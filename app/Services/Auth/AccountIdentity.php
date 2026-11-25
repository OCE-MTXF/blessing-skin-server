<?php

namespace App\Services\Auth;

use App\Models\User;
use Illuminate\Http\Request;

final class AccountIdentity
{
    public static function email(string $email): string
    {
        return mb_strtolower(trim($email), 'UTF-8');
    }

    public static function emailKey(string $email): ?string
    {
        $email = self::email($email);

        return $email === '' ? null : hash('sha256', $email);
    }

    public static function findByEmail(string $email): ?User
    {
        $key = self::emailKey($email);

        return $key === null ? null : User::where('email_key', $key)->first();
    }

    public static function canMail(?string $email): bool
    {
        return is_string($email) && strlen($email) <= 100
            && filter_var($email, FILTER_VALIDATE_EMAIL, FILTER_FLAG_EMAIL_UNICODE) !== false;
    }

    public static function emailRules(): array
    {
        return ['bail', 'required', 'string', 'max:100', 'email', static function ($attribute, $value, $fail) {
            if (!self::canMail(self::email($value))) {
                $fail('validation.email')->translate();
            }
        }];
    }

    public static function source(Request $request): string
    {
        $ip = $request->ip();
        if (!is_string($ip) || filter_var($ip, FILTER_VALIDATE_IP) === false) {
            return 'unknown';
        }

        // Use a single bucket for alternate representations of the same address.
        $packed = inet_pton($ip);
        if (strlen($packed) === 16 && str_starts_with($packed, str_repeat("\0", 10)."\xff\xff")) {
            $packed = substr($packed, 12);
        }

        return inet_ntop($packed);
    }
}
