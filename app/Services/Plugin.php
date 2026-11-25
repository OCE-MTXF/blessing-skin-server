<?php

declare(strict_types=1);

namespace App\Services;

use Illuminate\Support\Arr;
use Illuminate\Support\Str;
use LogicException;

class Plugin
{
    public const README_FILES = ['README.md', 'readme.md', 'README.MD'];

    private const ASSET_TYPES = [
        'css' => 'text/css; charset=UTF-8',
        'js' => 'text/javascript; charset=UTF-8',
        'mjs' => 'text/javascript; charset=UTF-8',
        'gif' => 'image/gif',
        'jpeg' => 'image/jpeg',
        'jpg' => 'image/jpeg',
        'png' => 'image/png',
        'webp' => 'image/webp',
        'woff' => 'font/woff',
        'woff2' => 'font/woff2',
    ];

    private bool $enabled = false;

    public function __construct(
        protected string $path,
        protected array $manifest,
        private ?string $sha256 = null,
        private array $files = [],
    ) {
    }

    public function __get(string $name)
    {
        return $this->getManifestAttr(Str::snake($name, '-'));
    }

    public function getManifest(): array
    {
        return $this->manifest;
    }

    public function getManifestAttr(string $name, $default = null)
    {
        return Arr::get($this->manifest, $name, $default);
    }

    public function displayTitle(): string
    {
        return $this->displayText('title', $this->name ?? '');
    }

    public function displayText(string $key, string $fallback = ''): string
    {
        $value = $this->getManifestAttr($key);
        if (!is_string($value) || $value === '' || strlen($value) > 4096) {
            return $fallback;
        }

        // Only active, approved plugin namespaces have been registered with the translator.
        $translated = $this->isEnabled() ? trans($value) : $value;

        return is_string($translated) ? $translated : $value;
    }

    public function getDigest(): ?string
    {
        return $this->sha256;
    }

    public function isApproved(): bool
    {
        return $this->sha256 !== null;
    }

    public function assets(string $relativeUri): string
    {
        if (!$this->isEnabled() || $this->getAsset($relativeUri) === null) {
            throw new LogicException('No approved plugin asset matches the requested path.');
        }

        // The route may not be registered yet during an approved plugin's bootstrap.
        // This path uses only the verified name, digest, and a portable URL path.
        return '/plugin-assets/'.$this->name.'/'.$this->sha256.'/'.$relativeUri;
    }

    public function getAsset(string $relative): ?array
    {
        // Reject executable/container document types and misleading compound suffixes.
        if (!PluginPackage::validRelativePath($relative)
            || preg_match('/(?:\A|[\/.])(?:php\d*|phtml|phar|cgi|pl|asp[x]?|jsp|shtml|html?|svgz?)(?:[\/.]|\z)/i', $relative)) {
            return null;
        }

        $extension = strtolower(pathinfo($relative, PATHINFO_EXTENSION));
        $type = self::ASSET_TYPES[$extension] ?? null;
        if ($type === null || ($path = $this->approvedFile('assets/'.$relative)) === null) {
            return null;
        }

        return ['path' => $path, 'type' => $type, 'sha256' => $this->files['assets/'.$relative]];
    }

    public function getReadme(): ?string
    {
        return Arr::first(self::README_FILES, fn ($filename) => $this->approvedFile($filename) !== null);
    }

    public function readReadme(): ?string
    {
        $readme = $this->getReadme();
        $path = $readme === null ? null : $this->approvedFile($readme);
        $text = $path === null ? false : @file_get_contents($path, false, null, 0, 256 * 1024 + 1);

        return $text !== false && strlen($text) <= 256 * 1024 && hash_equals($this->files[$readme], hash('sha256', $text))
            ? $text
            : null;
    }

    public function approvedFile(string $relative): ?string
    {
        if (!$this->isApproved() || !isset($this->files[$relative])) {
            return null;
        }

        $path = $this->path.DIRECTORY_SEPARATOR.str_replace('/', DIRECTORY_SEPARATOR, $relative);

        return is_file($path) && PluginPackage::canonicalPath($path) !== null ? $path : null;
    }

    public function hasConfig(): bool
    {
        return $this->hasConfigClass() || $this->hasConfigView();
    }

    public function hasConfigClass(): bool
    {
        return $this->getConfigClass() !== null;
    }

    public function getConfigClass(): ?string
    {
        $name = $this->getManifestAttr('enchants.config');
        $namespace = $this->namespace;
        if (!$this->isApproved() || !is_string($name) || !is_string($namespace)) {
            return null;
        }

        $class = Str::start($name, $namespace.'\\');

        return preg_match('/\A[A-Za-z_][A-Za-z0-9_]*(?:\\\\[A-Za-z_][A-Za-z0-9_]*)*\z/', $class) ? $class : null;
    }

    public function getViewPath(string $filename): string
    {
        return $this->path."/views/$filename";
    }

    public function getConfigView()
    {
        $path = $this->configViewPath();

        return $this->isEnabled() && $path !== null ? view()->file($path) : null;
    }

    public function hasConfigView(): bool
    {
        return $this->configViewPath() !== null;
    }

    private function configViewPath(): ?string
    {
        $file = $this->getManifestAttr('config', 'config.blade.php');

        return is_string($file) && PluginPackage::validRelativePath($file) ? $this->approvedFile('views/'.$file) : null;
    }

    public function setEnabled(bool $enabled): self
    {
        $this->enabled = $enabled && $this->isApproved();

        return $this;
    }

    public function isEnabled(): bool
    {
        return $this->enabled;
    }

    public function getPath(): string
    {
        return $this->path;
    }
}
