<?php

namespace App\Http\Controllers;

use App\Services\Plugin;
use App\Services\PluginManager;
use App\Services\PluginPackage;
use Composer\Semver\Comparator;
use Illuminate\Support\Collection;
use UnexpectedValueException;

class MarketController extends Controller
{
    public function marketData(PluginManager $manager): Collection
    {
        $items = [];
        foreach ($this->catalog() as $item) {
            $name = $item['name'] ?? null;
            $version = $item['version'] ?? null;
            if (!is_string($name) || !PluginPackage::validName($name) || !is_string($version)
                || !preg_match('/\A[A-Za-z0-9][A-Za-z0-9._+-]{0,127}\z/', $version) || isset($items[$name])) {
                continue;
            }
            $plugin = $manager->get($name);
            $installed = $plugin?->version ?? false;
            try {
                $canUpdate = $installed !== false && Comparator::greaterThan($version, $installed);
            } catch (UnexpectedValueException) {
                $canUpdate = false;
            }
            $requirements = [];
            foreach (is_array($item['require'] ?? null) ? array_slice($item['require'], 0, 128, true) : [] as $key => $value) {
                if (PluginPackage::validName((string) $key) && is_string($value) && strlen($value) <= 256) {
                    $requirements[$key] = $value;
                }
            }
            $title = $this->text($item['title'] ?? null, 512, $name);
            $description = $this->text($item['description'] ?? null, 4096);
            $author = $this->text($item['author'] ?? null, 512);
            $items[$name] = [
                'name' => $name,
                'version' => $version,
                'title' => $title,
                'description' => $description,
                'author' => $author,
                'installed' => $installed,
                'can_update' => $canUpdate,
                'dependencies' => [
                    'all' => (object) $requirements,
                    'unsatisfied' => (object) $manager->getUnsatisfied(new Plugin('', ['require' => $requirements]))->all(),
                ],
            ];
        }

        return collect($items)->values();
    }

    private function text(mixed $value, int $bytes, string $default = ''): string
    {
        return is_string($value) && $value !== '' ? mb_strcut($value, 0, $bytes, 'UTF-8') : $default;
    }

    private function catalog(): array
    {
        $path = config('plugins.catalog_file');
        if (!is_string($path) || $path === '' || PluginPackage::canonicalPath($path) === null || !is_file($path)) {
            return [];
        }

        $maxBytes = 1024 * 1024;
        $raw = @file_get_contents($path, false, null, 0, $maxBytes + 1);
        $catalog = $raw !== false && strlen($raw) <= $maxBytes ? json_decode($raw, true, 16) : null;
        $packages = $catalog['packages'] ?? null;

        return is_array($packages) && array_is_list($packages) ? array_slice($packages, 0, 400) : [];
    }
}
