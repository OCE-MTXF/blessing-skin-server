<?php

namespace App\Http\Middleware;

use Illuminate\Foundation\Http\Middleware\TrimStrings as Middleware;

final class TrimStrings extends Middleware
{
    protected $except = [
        'current_password', 'password', 'password_confirmation', 'new_password', 'confirmation',
        'token', 'code', 'client_secret', 'refresh_token', 'auth_token', 'code_verifier',
    ];
}
