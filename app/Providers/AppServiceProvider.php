<?php

namespace App\Providers;

use App\Services;
use Blessing\Filter;
use Illuminate\Pagination\Paginator;
use Illuminate\Support\Facades\URL;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->singleton('cipher', Services\Auth\PasswordCipher::class);
        $this->app->singleton(Filter::class);
        $this->app->alias(Filter::class, 'filter');
        $this->app->singleton(Services\Option::class);
        $this->app->alias(Services\Option::class, 'options');
        $this->app->singleton(Services\SafeMarkdown::class);
    }

    public function boot(): void
    {
        Paginator::useBootstrapFour();

        $url = (string) config('app.url');
        $scheme = parse_url($url, PHP_URL_SCHEME);
        if (in_array($scheme, ['http', 'https'], true)) {
            URL::useOrigin($url);
            URL::forceScheme($scheme);
        }
    }
}
