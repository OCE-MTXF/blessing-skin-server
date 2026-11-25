<?php

namespace App\Console\Commands;

use App\Services\PluginManager;
use Illuminate\Console\Command;

class PluginEnableCommand extends Command
{
    protected $signature = 'plugin:enable {name}';

    protected $description = 'Select an offline-approved plugin for activation';

    public function handle(PluginManager $plugins): int
    {
        $name = $this->argument('name');
        $result = $plugins->enable($name);
        if ($result === true) {
            $this->info(trans('admin.plugins.operations.enabled', ['plugin' => $name]));

            return self::SUCCESS;
        }
        if (is_array($result)) {
            foreach ($plugins->formatUnresolved($result['unsatisfied'], $result['conflicts']) as $reason) {
                $this->warn($reason);
            }
        } else {
            $this->warn('Cannot enable this plugin: deployment approval or installation readiness is missing.');
        }

        return self::FAILURE;
    }
}
