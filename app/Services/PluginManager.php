<?php

declare(strict_types=1);

namespace App\Services;

use App\Events;
use App\Services\Installation\Readiness;
use App\Services\Installation\StateStore;
use Composer\Autoload\ClassLoader;
use Composer\Semver\Semver;
use Composer\Semver\VersionParser;
use Illuminate\Contracts\Events\Dispatcher;
use Illuminate\Contracts\Foundation\Application;
use Illuminate\Filesystem\Filesystem;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Throwable;

class PluginManager
{
    private bool $booted = false;

    private ClassLoader $loader;

    private ?Collection $plugins = null;

    /** The eligible installed set, resolved in dependency order. */
    private array $active = [];

    /** Packages that actually booted successfully in this application instance. */
    private array $loaded = [];

    private ?array $approvals = null;

    public function __construct(
        protected Application $app,
        protected Option $option,
        protected Dispatcher $dispatcher,
        protected Filesystem $filesystem,
    ) {
        $this->loader = new ClassLoader();
    }

    public function all(): Collection
    {
        if ($this->plugins !== null) {
            return $this->plugins;
        }

        $this->plugins = $this->inventory($this->readApprovals());
        foreach ($this->loaded as $name => $plugin) {
            $this->plugins->put($name, $plugin);
        }

        return $this->plugins->sortKeys(SORT_STRING);
    }

    /** Installed metadata is inert until an exact deploy approval also matches the tree. */
    private function inventory(array $approvals, ?array $requested = null): Collection
    {
        $candidates = [];
        $ambiguous = [];
        foreach ($this->getPluginsDirs() as $directory) {
            foreach ($this->filesystem->directories($directory) as $path) {
                if ($this->trustedPath($path) === null || !PluginPackage::validRelativePath(basename($path))) {
                    continue;
                }
                $manifest = PluginPackage::manifest($path);
                if ($manifest === null || ($requested !== null && !isset($requested[$manifest['name']]))) {
                    continue;
                }
                $name = $manifest['name'];
                if (isset($candidates[$name])) {
                    $ambiguous[$name] = true;
                } else {
                    $candidates[$name] = [$path, $manifest];
                }
            }
        }

        $plugins = collect();
        foreach ($candidates as $name => [$path, $manifest]) {
            $approval = $approvals[$name] ?? null;
            $verified = null;
            if ($approval !== null && !isset($ambiguous[$name]) && $manifest['version'] === $approval['version']) {
                try {
                    $tree = PluginPackage::inspect($path, $this->requireReadOnly());
                    $verifiedManifest = PluginPackage::manifest($path, $tree['files']['package.json'] ?? '');
                    if (hash_equals($approval['sha256'], $tree['sha256']) && $verifiedManifest !== null
                        && $verifiedManifest['name'] === $name && $verifiedManifest['version'] === $approval['version']
                        && $this->validRuntimeManifest($verifiedManifest)) {
                        $verified = $tree;
                        $manifest = $verifiedManifest;
                    }
                } catch (Throwable) {
                    // Unreadable, non-regular, writable or invalid packages are not approved.
                }
            }
            $plugins->put($name, new Plugin($path, $manifest, $verified['sha256'] ?? null, $verified['files'] ?? []));
        }

        return $plugins->sortKeys(SORT_STRING);
    }

    private function readApprovals(): array
    {
        if ($this->approvals !== null) {
            return $this->approvals;
        }
        $this->approvals = [];
        $pin = config('plugins.approvals_sha256');
        $configured = config('plugins.approvals_file');
        if (!is_string($pin) || !PluginPackage::validDigest($pin)
            || ($path = $this->trustedPath($configured)) === null || !is_file($path)
            || ((@stat($path))['nlink'] ?? null) !== 1) {
            return [];
        }

        $raw = @file_get_contents($path, false, null, 0, PluginPackage::MAX_MANIFEST_BYTES + 1);
        if ($raw === false || strlen($raw) > PluginPackage::MAX_MANIFEST_BYTES || !hash_equals($pin, hash('sha256', $raw))) {
            return [];
        }
        $file = json_decode($raw, true, 16);

        return $this->approvals = ($file['schema'] ?? null) === 1 ? $this->parseRecords($file['packages'] ?? null) : [];
    }

    private function readRequested(): array
    {
        $option = $this->option->get('plugins_enabled', '[]', true);

        return $this->parseRecords(is_string($option) && strlen($option) <= PluginPackage::MAX_MANIFEST_BYTES
            ? json_decode($option, true, 16)
            : null);
    }

    private function parseRecords(mixed $list): array
    {
        if (!is_array($list) || !array_is_list($list) || count($list) > 128) {
            return [];
        }
        $records = [];
        $ambiguous = [];
        foreach ($list as $record) {
            if (!is_array($record)
                || !is_string($name = $record['name'] ?? null) || !PluginPackage::validName($name)
                || !is_string($version = $record['version'] ?? null) || !preg_match('/\A[A-Za-z0-9][A-Za-z0-9._+-]{0,127}\z/', $version)
                || !is_string($sha256 = $record['sha256'] ?? null) || !PluginPackage::validDigest($sha256)) {
                continue;
            }
            if (isset($records[$name])) {
                $ambiguous[$name] = true;
            }
            $records[$name] = compact('name', 'version', 'sha256');
        }

        return array_diff_key($records, $ambiguous);
    }

    private function validRuntimeManifest(array $manifest): bool
    {
        $namespace = $manifest['namespace'] ?? null;
        if (!is_string($namespace)
            || !preg_match('/\A[A-Za-z_][A-Za-z0-9_]*(?:\\\\[A-Za-z_][A-Za-z0-9_]*)*\z/', $namespace)) {
            return false;
        }

        $parser = new VersionParser();
        $enchants = $manifest['enchants'] ?? [];
        if (!is_array($enchants)) {
            return false;
        }
        try {
            $parser->normalize($manifest['version']);
            foreach ([$manifest['require'] ?? [], $enchants['conflicts'] ?? []] as $dependencies) {
                if (!is_array($dependencies) || count($dependencies) > 128) {
                    return false;
                }
                foreach ($dependencies as $name => $constraint) {
                    if (!PluginPackage::validName((string) $name) || !is_string($constraint) || strlen($constraint) > 256) {
                        return false;
                    }
                    $parser->parseConstraints($constraint);
                }
            }
        } catch (\UnexpectedValueException) {
            return false;
        }

        return true;
    }

    private function resolveActive(Collection $plugins, array $requested): void
    {
        $this->active = [];
        foreach ($plugins as $name => $plugin) {
            $expected = $requested[$name] ?? null;
            if ($plugin->isApproved() && $expected !== null
                && $expected['version'] === $plugin->version && $expected['sha256'] === $plugin->getDigest()) {
                $this->active[$name] = $expected;
            }
        }

        // Drop failures simultaneously, and repeat to remove transitive dependants.
        do {
            $invalid = [];
            $namespaces = [];
            foreach ($this->active as $name => $info) {
                $plugin = $plugins->get($name);
                $namespace = strtolower($plugin->namespace);
                if (isset($namespaces[$namespace])) {
                    $invalid[$name] = $invalid[$namespaces[$namespace]] = true;
                }
                $namespaces[$namespace] = $name;
                if ($this->getUnsatisfied($plugin)->isNotEmpty()) {
                    $invalid[$name] = true;
                }
                foreach ($this->getConflicts($plugin) as $other => $detail) {
                    $invalid[$name] = $invalid[$other] = true;
                }
            }
            $this->active = array_diff_key($this->active, $invalid);
        } while ($invalid !== []);

        // Load prerequisites first; cycles and everything depending on them stay off.
        $ordered = [];
        do {
            $remaining = count($ordered);
            foreach (array_diff_key($this->active, $ordered) as $name => $info) {
                $requirements = array_diff_key($plugins->get($name)->getManifestAttr('require', []), [
                    'php' => true, 'blessing-skin-server' => true,
                ]);
                if (array_diff_key($requirements, $ordered) === []) {
                    $ordered[$name] = $info;
                }
            }
        } while (count($ordered) > $remaining);
        $this->active = $ordered;
    }

    public function boot(): void
    {
        if ($this->booted) {
            return;
        }
        $this->booted = true;
        $approvals = $this->readApprovals();
        if ($approvals === [] || !$this->app->make(Readiness::class)->isReady()) {
            return;
        }
        $requested = array_filter($this->readRequested(), fn ($record) => ($approvals[$record['name']] ?? null) === $record);
        if ($requested === []) {
            return;
        }

        $plugins = $this->inventory($approvals, $requested);
        $this->resolveActive($plugins, $requested);
        if ($this->active === []) {
            return;
        }
        $this->loader->register();
        foreach ($this->active as $name => $record) {
            $plugin = $plugins->get($name);
            if ($this->getUnsatisfied($plugin)->isNotEmpty()) {
                unset($this->active[$name]);

                continue;
            }
            $this->bootPlugin($plugin);
        }
    }

    private function bootPlugin(Plugin $plugin): void
    {
        $path = $plugin->getPath();
        $namespace = $plugin->namespace;
        $prefix = $namespace.'\\';
        $plugin->setEnabled(true);
        // A reviewed bootstrap may refer to its own package via plugin()/plugin_assets().
        $this->loaded[$plugin->name] = $plugin;
        try {
            $this->loader->addPsr4($prefix, $path.'/src');
            $this->app->make('translation.loader')->addNamespace($namespace, $path.'/lang');
            $this->app->make('view')->addNamespace($namespace, $path.'/views');
            if (($autoload = $plugin->approvedFile('vendor/autoload.php')) !== null) {
                $this->filesystem->getRequire($autoload);
            }
            $providers = $plugin->getManifestAttr('enchants.providers', []);
            foreach (is_array($providers) ? $providers : [] as $provider) {
                if (!is_string($provider) || !preg_match('/\A[A-Za-z_][A-Za-z0-9_]*(?:\\\\[A-Za-z_][A-Za-z0-9_]*)*\z/', $provider)) {
                    continue;
                }
                $class = $prefix.Str::finish($provider, 'ServiceProvider');
                if (class_exists($class)) {
                    $this->app->register($class);
                }
            }
            if (($bootstrap = $plugin->approvedFile('bootstrap.php')) !== null) {
                $this->app->call($this->filesystem->getRequire($bootstrap), ['plugin' => $plugin]);
            }
            $this->plugins?->put($plugin->name, $plugin);
        } catch (Throwable $error) {
            unset($this->active[$plugin->name], $this->loaded[$plugin->name]);
            $this->loader->setPsr4($prefix, []);
            $plugin->setEnabled(false);
            report($error);
            $this->dispatcher->dispatch(new Events\PluginBootFailed($plugin));
        }
    }

    public function get(string $name): ?Plugin
    {
        return $this->loaded[$name] ?? $this->all()->get($name);
    }

    /** An activation flag cannot install, approve or synchronously load package code. */
    public function enable(string $name): bool|array
    {
        if (!$this->get($name)?->isApproved()) {
            return false;
        }

        return $this->changeRequested(function (array $requested, Collection $plugins) use ($name) {
            $plugin = $plugins->get($name);
            if (!$plugin?->isApproved()) {
                return [false, $requested];
            }
            $unsatisfied = $this->getUnsatisfied($plugin);
            $conflicts = $this->getConflicts($plugin);
            foreach ($this->active as $otherName => $record) {
                if ($otherName === $name) {
                    continue;
                }
                $other = $plugins->get($otherName);
                $rule = $other->getManifestAttr('enchants.conflicts', [])[$name] ?? null;
                if ((is_string($rule) && $this->satisfies($plugin->version, $rule))
                    || strcasecmp($other->namespace, $plugin->namespace) === 0) {
                    $conflicts->put($otherName, ['version' => $other->version, 'constraint' => $rule]);
                }
            }
            if ($unsatisfied->isNotEmpty() || $conflicts->isNotEmpty()) {
                return [compact('unsatisfied', 'conflicts'), $requested];
            }
            $requested[$name] = ['name' => $name, 'version' => $plugin->version, 'sha256' => $plugin->getDigest()];

            return [true, $requested];
        });
    }

    public function disable(string $name): bool
    {
        return $this->changeRequested(function (array $requested, Collection $plugins) use ($name) {
            if (!isset($requested[$name])) {
                return [$plugins->has($name), $requested];
            }
            unset($requested[$name]);
            // Persistently deactivate dependants, even if currently ineligible.
            do {
                $count = count($requested);
                foreach ($plugins as $key => $plugin) {
                    $requirements = $plugin->getManifestAttr('require', []);
                    if (isset($requested[$key]) && $plugin->isApproved() && is_array($requirements)
                        && array_diff_key($requirements, $requested, ['php' => true, 'blessing-skin-server' => true]) !== []) {
                        unset($requested[$key]);
                    }
                }
            } while (count($requested) < $count);

            return [true, $requested];
        });
    }

    private function changeRequested(callable $mutate): mixed
    {
        if (!$this->app->make(Readiness::class)->isReady()) {
            return false;
        }

        return DB::transaction(function () use ($mutate) {
            // Serialize read-modify-write using the installed singleton row. SQLite rejects
            // a stale read-to-write upgrade with SQLITE_BUSY and Laravel retries it.
            $db = DB::connection();
            $state = $db->table(StateStore::TABLE)->where('id', 1)->lockForUpdate()->first();
            if ($state?->status !== StateStore::INSTALLED || $state->version !== config('app.version')) {
                return false;
            }
            $requested = $this->readRequested();
            $plugins = $this->inventory($this->readApprovals());
            $runtimeActive = $this->active;
            try {
                $this->resolveActive($plugins, $requested);
                [$result, $updated] = $mutate($requested, $plugins);
                if ($updated !== $requested) {
                    $this->option->set('plugins_enabled', json_encode(array_values($updated), JSON_THROW_ON_ERROR));
                }

                return $result;
            } finally {
                $this->active = $runtimeActive;
            }
        }, 3);
    }

    public function getEnabledPlugins(): Collection
    {
        return collect($this->loaded);
    }

    public function getUnsatisfied(Plugin $plugin): Collection
    {
        $requirements = $plugin->getManifestAttr('require', []);

        return collect(is_array($requirements) ? $requirements : [])->mapWithKeys(function ($constraint, $name) {
            $version = match ($name) {
                'blessing-skin-server' => config('app.version'),
                'php' => PHP_MAJOR_VERSION.'.'.PHP_MINOR_VERSION.'.'.PHP_RELEASE_VERSION,
                default => $this->active[$name]['version'] ?? null,
            };

            return ($version === null || !is_string($constraint) || !$this->satisfies($version, $constraint))
                ? [$name => compact('version', 'constraint')]
                : [];
        });
    }

    public function getConflicts(Plugin $plugin): Collection
    {
        $rules = $plugin->getManifestAttr('enchants.conflicts', []);

        return collect(is_array($rules) ? $rules : [])->mapWithKeys(function ($constraint, $name) {
            $version = $this->active[$name]['version'] ?? null;

            return $version !== null && is_string($constraint) && $this->satisfies($version, $constraint)
                ? [$name => compact('version', 'constraint')]
                : [];
        });
    }

    private function satisfies(string $version, string $constraint): bool
    {
        try {
            return Semver::satisfies($version, $constraint);
        } catch (\UnexpectedValueException) {
            return false;
        }
    }

    public function formatUnresolved(Collection $unsatisfied, Collection $conflicts): array
    {
        $unsatisfied = $unsatisfied->map(function ($detail, $name) {
            $title = match ($name) {
                'blessing-skin-server' => 'Blessing Skin Server',
                'php' => 'PHP',
                default => $this->get((string) $name)?->name ?? $name,
            };

            return $detail['version']
                ? trans('admin.plugins.operations.unsatisfied.version', ['title' => $title, 'constraint' => $detail['constraint']])
                : trans('admin.plugins.operations.unsatisfied.disabled', ['name' => $title]);
        })->values()->all();
        $conflicts = $conflicts->keys()->map(fn ($title) => trans('admin.plugins.operations.unsatisfied.conflict', compact('title')))->all();

        return array_merge($unsatisfied, $conflicts);
    }

    public function getPluginsDirs(): Collection
    {
        $config = config('plugins.directory');
        $roots = is_string($config) && $config !== '' ? explode(',', $config, 17) : [];

        return collect($roots)
            ->map(fn ($path) => $this->trustedPath(rtrim(trim($path), '/'.DIRECTORY_SEPARATOR)))
            ->filter(fn ($path) => $path !== null && is_dir($path))
            ->unique()->values();
    }

    private function trustedPath(mixed $path): ?string
    {
        if (!is_string($path) || $path === '' || ($canonical = PluginPackage::canonicalPath($path)) === null) {
            return null;
        }
        foreach ([public_path(), storage_path(), base_path('bootstrap/cache')] as $runtimeDirectory) {
            $runtimeDirectory = realpath($runtimeDirectory) ?: $runtimeDirectory;
            if (PluginPackage::containsPath($runtimeDirectory, $canonical)
                || PluginPackage::containsPath($canonical, $runtimeDirectory)) {
                return null;
            }
        }
        if ($this->requireReadOnly()) {
            // A writable containing directory could replace an otherwise read-only entry.
            for ($ancestor = $canonical; ; $ancestor = $parent) {
                if (is_writable($ancestor)) {
                    return null;
                }
                $parent = dirname($ancestor);
                if ($parent === $ancestor) {
                    break;
                }
            }
        }

        return $canonical;
    }

    protected function requireReadOnly(): bool
    {
        return true;
    }

    public function __destruct()
    {
        $this->loader->unregister();
    }
}
