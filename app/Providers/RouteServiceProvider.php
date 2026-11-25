<?php

namespace App\Providers;

use App\Events\ConfigureRoutes;
use App\Services\Auth\AccountIdentity;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Foundation\Support\Providers\RouteServiceProvider as ServiceProvider;
use Illuminate\Http\Request;
use Illuminate\Routing\Router;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Facades\Route;

class RouteServiceProvider extends ServiceProvider
{
    /**
     * This namespace is applied to your controller routes.
     * In addition, it is set as the URL generator's root namespace.
     */
    protected $namespace = 'App\Http\Controllers';

    public function boot(): void
    {
        foreach (['api' => 60, 'asset-source' => 300, 'asset-render' => 120, 'asset-upload' => 10, 'broadcast' => 4] as $name => $limit) {
            RateLimiter::for($name, static fn (Request $request) => Limit::perMinute($limit)->by(AccountIdentity::source($request)));
        }

        parent::boot();
    }

    /**
     * Define the routes for the application.
     */
    public function map(Router $router): void
    {
        $this->mapStaticRoutes($router);
        $this->mapWebRoutes($router);
        $this->mapApiRoutes();
        require base_path('routes/oauth.php');

        event(new ConfigureRoutes($router));
    }

    /**
     * Define the "web" routes for the application.
     * These routes all receive session state, CSRF protection, etc.
     */
    protected function mapWebRoutes(Router $router): void
    {
        Route::middleware(['web'])
            ->namespace($this->namespace)
            ->group(base_path('routes/web.php'));
    }

    /**
     * Define the "static" routes for the application.
     * These routes will not load session, etc.
     */
    protected function mapStaticRoutes(Router $router): void
    {
        Route::namespace($this->namespace)
            ->group(base_path('routes/static.php'));
    }

    /**
     * Define the "api" routes for the application.
     * These routes are typically stateless.
     */
    protected function mapApiRoutes(): void
    {
        Route::prefix('api')
            ->middleware(['api', 'throttle:api'])
            ->namespace($this->namespace)
            ->group(base_path('routes/api.php'));
    }
}
