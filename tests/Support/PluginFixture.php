<?php

namespace Tests\Support;

use App\Services\Option;
use App\Services\PluginManager;
use App\Services\PluginPackage;
use Illuminate\Contracts\Events\Dispatcher;
use Illuminate\Filesystem\Filesystem;

final class PluginFixture
{
    public readonly string $root;

    public readonly string $directory;

    public function __construct(callable $onTeardown)
    {
        // Separate from both the public root and the isolated application's storage.
        $this->root = realpath(sys_get_temp_dir()).DIRECTORY_SEPARATOR.'blessing-plugin-test-'.bin2hex(random_bytes(16));
        $this->directory = $this->root.DIRECTORY_SEPARATOR.'packages';
        mkdir($this->directory, 0700, true);
        $root = $this->root;
        $onTeardown(static function () use ($root): void {
            if (PluginPackage::canonicalPath($root) !== null && str_starts_with(basename($root), 'blessing-plugin-test-')) {
                (new Filesystem())->deleteDirectory($root);
            }
        });
        config(['plugins.directory' => $this->directory]);
    }

    public function package(string $name, array $manifest = [], ?string $directory = null): array
    {
        $path = $this->directory.DIRECTORY_SEPARATOR.($directory ?? $name);
        $manifest += [
            'name' => $name,
            'version' => '1.2.3',
            'namespace' => 'PluginFixture'.ucfirst(str_replace('-', '', $name)),
            'title' => 'Demo '.$name,
            'description' => 'Reviewed local text.',
        ];
        mkdir($path, 0700);
        mkdir($path.'/assets', 0700);
        mkdir($path.'/assets/styles', 0700);
        mkdir($path.'/views', 0700);
        file_put_contents($path.'/package.json', json_encode($manifest, JSON_THROW_ON_ERROR));
        file_put_contents($path.'/README.md', "# Reviewed documentation\n\nA **local** example.\n");
        file_put_contents($path.'/assets/styles/demo.css', '.plugin-demo { color: blue; }');
        file_put_contents($path.'/assets/note.html', '<p>Local documentation.</p>');
        file_put_contents($path.'/views/config.blade.php', '<p>Plugin configuration.</p>');
        file_put_contents($path.'/bootstrap.php', <<<'PHP'
            <?php
            return static function (\App\Services\Plugin $plugin): void {
                app()->instance('tests.plugin.loaded.'.$plugin->name, true);
                app()->instance('tests.plugin.asset.'.$plugin->name, $plugin->assets('styles/demo.css'));
            };
            PHP);

        return ['name' => $name, 'version' => $manifest['version'], 'sha256' => PluginPackage::inspect($path)['sha256']];
    }

    public function approve(array ...$records): void
    {
        $file = $this->root.DIRECTORY_SEPARATOR.'approval.json';
        $bytes = json_encode(['schema' => 1, 'packages' => $records], JSON_THROW_ON_ERROR);
        file_put_contents($file, $bytes);
        config(['plugins.approvals_file' => $file, 'plugins.approvals_sha256' => hash('sha256', $bytes)]);
    }

    public function select(array ...$records): void
    {
        option(['plugins_enabled' => json_encode($records, JSON_THROW_ON_ERROR)]);
    }

    public function manager(bool $withWritableFixtures = true): PluginManager
    {
        if (!$withWritableFixtures) {
            return app()->make(PluginManager::class);
        }

        // PHPUnit owns its synthetic files. Keep the production permission gate on by default.
        return new class(app(), app(Option::class), app(Dispatcher::class), app(Filesystem::class)) extends PluginManager
        {
            protected function requireReadOnly(): bool
            {
                return false;
            }
        };
    }
}
