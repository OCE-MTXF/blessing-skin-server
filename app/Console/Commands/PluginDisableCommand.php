<?php

namespace App\Console\Commands;

use App\Services\PluginManager;
use Illuminate\Console\Command;

class PluginDisableCommand extends Command
{
    protected $signature = 'plugin:disable {name}';

    protected $description = 'Disable a plugin';

    public function handle(PluginManager $plugins): int
    {
        $name = $this->argument('name');
        if (!$plugins->disable($name)) {
            $this->warn('Cannot disable this plugin: the plugin or installation readiness is missing.');

            return self::FAILURE;
        }
        $this->info(trans('admin.plugins.operations.disabled', ['plugin' => $name]));

        return self::SUCCESS;
    }
}
