<?php

namespace App\Providers;

use App\Models\Scope;
use App\Models\User;
use App\Services\Auth\AccountAccessTokens;
use App\Services\Auth\AccountSecurity;
use App\Services\Installation\Readiness;
use DateInterval;
use Illuminate\Auth\Events\Login;
use Illuminate\Foundation\Support\Providers\AuthServiceProvider as ServiceProvider;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Event;
use Laravel\Passport\Bridge\AccessTokenRepository;
use Laravel\Passport\Passport;
use Throwable;

class AuthServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        parent::register();
        Passport::ignoreRoutes();
        Passport::$clientUuids = false;
        Passport::$deviceCodeGrantEnabled = false;
        $this->app->bind(AccessTokenRepository::class, AccountAccessTokens::class);
    }

    public function boot(): void
    {
        Event::listen(Login::class, static function (Login $login) {
            if ($login->guard === 'web' && $login->user instanceof User) {
                Auth::guard('web')->getSession()->put(AccountSecurity::SESSION_VERSION, $login->user->account_version);
            }
        });
        Passport::authorizationView('auth.oauth-authorize');
        Passport::tokensExpireIn(new DateInterval('PT1H'));
        Passport::refreshTokensExpireIn(new DateInterval('P30D'));
        Passport::personalAccessTokensExpireIn(new DateInterval('P30D'));
        $defaults = [
            'User.Read' => 'auth.oauth.scope.user.read',
            'Notification.Read' => 'auth.oauth.scope.notification.read',
            'Notification.ReadWrite' => 'auth.oauth.scope.notification.readwrite',
            'Notification.Acknowledge' => 'Mark your notifications as read',
            'Player.Read' => 'auth.oauth.scope.player.read',
            'Player.ReadWrite' => 'auth.oauth.scope.player.readwrite',
            'Closet.Read' => 'auth.oauth.scope.closet.read',
            'Closet.ReadWrite' => 'auth.oauth.scope.closet.readwrite',
            'UsersManagement.Read' => 'auth.oauth.scope.users-management.read',
            'UsersManagement.ReadWrite' => 'auth.oauth.scope.users-management.readwrite',
            'PlayersManagement.Read' => 'auth.oauth.scope.players-management.read',
            'PlayersManagement.ReadWrite' => 'auth.oauth.scope.players-management.readwrite',
            'ClosetManagement.Read' => 'auth.oauth.scope.closet-management.read',
            'ClosetManagement.ReadWrite' => 'auth.oauth.scope.closet-management.readwrite',
            'ReportsManagement.Read' => 'auth.oauth.scope.reports-management.read',
            'ReportsManagement.ReadWrite' => 'auth.oauth.scope.reports-management.readwrite',
        ];
        $custom = [];
        try {
            if (app(Readiness::class)->isReady()) {
                $custom = Scope::pluck('description', 'name')->toArray();
                unset($custom['Closet.ReadWrtie'], $custom['*']);
            }
        } catch (Throwable) {
            // Be conservative during startup, migrations, and a transient DB outage.
        }
        Passport::tokensCan($defaults + $custom);
        Passport::setDefaultScope(['User.Read']);
    }
}
