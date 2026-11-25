<?php

namespace App\Console\Commands;

use App\Services\PluginPackage;
use Illuminate\Console\Command;
use Throwable;

class PluginDigestCommand extends Command
{
    protected $signature = 'plugin:digest {directory : Absolute path to the reviewed package directory}';

    protected $description = 'Print an approval record for an offline-reviewed plugin; never approve or activate it';

    public function handle(): int
    {
        $directory = $this->argument('directory');
        if (!is_string($directory) || ($manifest = PluginPackage::manifest($directory)) === null) {
            $this->error('Expected an absolute canonical path to a package with a valid package.json.');

            return self::FAILURE;
        }

        try {
            $inspection = PluginPackage::inspect($directory);
            $manifest = PluginPackage::manifest($directory, $inspection['files']['package.json'] ?? '');
            if ($manifest === null) {
                throw new \RuntimeException('Package metadata changed during inspection.');
            }
        } catch (Throwable $e) {
            $this->error($e->getMessage());

            return self::FAILURE;
        }

        $this->line(json_encode([
            'name' => $manifest['name'],
            'version' => $manifest['version'],
            'sha256' => $inspection['sha256'],
        ], JSON_THROW_ON_ERROR));

        return self::SUCCESS;
    }
}
