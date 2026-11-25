<?php

namespace App\Providers;

use App\Console\Commands\PluginDigestCommand;
use App\Services\PluginManager;
use Illuminate\Support\ServiceProvider;

class PluginServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->singleton(PluginManager::class);
        $this->app->alias(PluginManager::class, 'plugins');
    }

    public function boot(): void
    {
        $this->commands([PluginDigestCommand::class]);

        // Lifecycle and review commands must boot only core code, not installed plugins.
        if (!$this->app->runningInConsole()) {
            $this->app->make(PluginManager::class)->boot();
        }
    }
}
