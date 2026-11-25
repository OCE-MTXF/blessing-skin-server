<?php

namespace App\Http;

use Illuminate\Foundation\Http\Kernel as HttpKernel;

class Kernel extends HttpKernel
{
    /**
     * The application's global HTTP middleware stack.
     *
     * These middleware are run during every request to your application.
     *
     * @var array<int, class-string|string>
     */
    protected $middleware = [
        Middleware\SecurityHeaders::class,
        Middleware\CanonicalOrigin::class,
        \Illuminate\Foundation\Http\Middleware\PreventRequestsDuringMaintenance::class,
        Middleware\RequireReadyInstallation::class,
        \Illuminate\Foundation\Http\Middleware\ValidatePostSize::class,
        Middleware\TrimStrings::class,
        Middleware\ConvertEmptyStringsToNull::class,
        Middleware\DetectLanguagePrefer::class,
    ];

    /**
     * The application's route middleware groups.
     *
     * @var array<string, array<int, class-string|string>>
     */
    protected $middlewareGroups = [
        'web' => [
            \Illuminate\Cookie\Middleware\EncryptCookies::class,
            \Illuminate\Cookie\Middleware\AddQueuedCookiesToResponse::class,
            \Illuminate\Session\Middleware\StartSession::class,
            Middleware\EnforceAccountVersion::class,
            \Illuminate\View\Middleware\ShareErrorsFromSession::class,
            \Illuminate\Foundation\Http\Middleware\VerifyCsrfToken::class,
            Middleware\EnforceEverGreen::class,
            \Illuminate\Routing\Middleware\SubstituteBindings::class,
        ],

        'api' => [
            \Illuminate\Routing\Middleware\SubstituteBindings::class,
        ],

        'authorize' => [
            'auth:web',
            Middleware\RejectBannedUser::class,
            Middleware\EnsureEmailFilled::class,
            Middleware\FireUserAuthenticated::class,
        ],

        'oauth-api' => [
            \App\Services\Auth\RequireOAuthBearer::class,
            'auth:oauth',
            Middleware\RejectBannedUser::class,
            Middleware\EnsureEmailFilled::class.':oauth',
            'verified',
        ],
    ];

    protected $middlewarePriority = [
        \Illuminate\Foundation\Http\Middleware\HandlePrecognitiveRequests::class,
        \Illuminate\Cookie\Middleware\EncryptCookies::class,
        \Illuminate\Cookie\Middleware\AddQueuedCookiesToResponse::class,
        \Illuminate\Session\Middleware\StartSession::class,
        Middleware\EnforceAccountVersion::class,
        \Illuminate\View\Middleware\ShareErrorsFromSession::class,
        \Illuminate\Foundation\Http\Middleware\VerifyCsrfToken::class,
        \Illuminate\Routing\Middleware\ThrottleRequests::class,
        \Illuminate\Routing\Middleware\ThrottleRequestsWithRedis::class,
        \App\Services\Auth\RequireOAuthBearer::class,
        \Illuminate\Contracts\Auth\Middleware\AuthenticatesRequests::class,
        Middleware\RejectBannedUser::class,
        Middleware\EnsureEmailFilled::class,
        Middleware\CheckUserVerified::class,
        Middleware\CheckRole::class,
        \Laravel\Passport\Http\Middleware\CheckToken::class,
        \Laravel\Passport\Http\Middleware\CheckTokenForAnyScope::class,
        \Illuminate\Contracts\Session\Middleware\AuthenticatesSessions::class,
        \Illuminate\Routing\Middleware\SubstituteBindings::class,
        \Illuminate\Auth\Middleware\Authorize::class,
    ];

    /**
     * The application's middleware aliases.
     *
     * Aliases may be used instead of class names to conveniently assign middleware to routes and groups.
     *
     * @var array<string, class-string|string>
     */
    protected $middlewareAliases = [
        'auth' => Middleware\Authenticate::class,
        'cache.headers' => \Illuminate\Http\Middleware\SetCacheHeaders::class,
        'guest' => Middleware\RedirectIfAuthenticated::class,
        'role' => Middleware\CheckRole::class,
        'throttle' => \Illuminate\Routing\Middleware\ThrottleRequests::class,
        'verified' => Middleware\CheckUserVerified::class,
        'scope' => \Laravel\Passport\Http\Middleware\CheckTokenForAnyScope::class,
        'scopes' => \Laravel\Passport\Http\Middleware\CheckToken::class,
    ];
}
