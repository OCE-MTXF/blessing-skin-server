<?php

declare(strict_types=1);

namespace App\Services;

use FilesystemIterator;
use RuntimeException;
use SplFileInfo;

/** The deploy tool and the runtime must agree on this exact tree format. */
final class PluginPackage
{
    private const MAX_ENTRIES = 20000;
    private const MAX_BYTES = 256 * 1024 * 1024;
    public const MAX_MANIFEST_BYTES = 64 * 1024;
    public const NAME_PATTERN = '[a-z0-9][a-z0-9_-]{0,63}';

    public static function validName(string $name): bool
    {
        return preg_match('/\A'.self::NAME_PATTERN.'\z/', $name) === 1;
    }

    public static function validDigest(string $digest): bool
    {
        return preg_match('/\A[0-9a-f]{64}\z/', $digest) === 1;
    }

    public static function canonicalPath(string $path): ?string
    {
        $canonical = realpath($path);

        return $canonical !== false && self::samePath($path, $canonical) && !is_link($path)
            ? $canonical
            : null;
    }

    public static function containsPath(string $directory, string $path): bool
    {
        $directory = rtrim(self::pathKey($directory), '/');
        $path = self::pathKey($path);

        return $path === $directory || str_starts_with($path, $directory.'/');
    }

    public static function samePath(string $left, string $right): bool
    {
        return self::pathKey($left) === self::pathKey($right);
    }

    private static function pathKey(string $path): string
    {
        return DIRECTORY_SEPARATOR === '\\' ? strtolower(str_replace('\\', '/', $path)) : $path;
    }

    public static function validRelativePath(string $path): bool
    {
        if (strlen($path) > 512) {
            return false;
        }

        foreach (explode('/', $path) as $segment) {
            if (!preg_match('/\A[A-Za-z0-9_.@+-]{1,160}\z/', $segment)
                || str_ends_with($segment, '.')
                || preg_match('/\A(?:con|prn|aux|nul|com[0-9]|lpt[0-9])(?:\.|\z)/i', $segment)) {
                return false;
            }
        }

        return true;
    }

    public static function manifest(string $directory, ?string $sha256 = null): ?array
    {
        $path = $directory.DIRECTORY_SEPARATOR.'package.json';
        if (!is_file($path) || self::canonicalPath($path) === null) {
            return null;
        }

        $raw = @file_get_contents($path, false, null, 0, self::MAX_MANIFEST_BYTES + 1);
        if ($raw === false || strlen($raw) > self::MAX_MANIFEST_BYTES
            || ($sha256 !== null && !hash_equals($sha256, hash('sha256', $raw)))) {
            return null;
        }

        $manifest = json_decode($raw, true, 32);
        if (!is_array($manifest)
            || !is_string($manifest['name'] ?? null)
            || !self::validName($manifest['name'])
            || !is_string($manifest['version'] ?? null)
            || !preg_match('/\A[A-Za-z0-9][A-Za-z0-9._+-]{0,127}\z/', $manifest['version'])) {
            return null;
        }

        return $manifest;
    }

    public static function inspect(string $directory, bool $requireReadOnly = false): array
    {
        if (!is_dir($directory) || self::canonicalPath($directory) === null
            || ($requireReadOnly && is_writable($directory))) {
            throw new RuntimeException('Invalid plugin package directory.');
        }

        $entries = 0;
        $bytes = 0;
        $files = [];
        $caseKeys = [];
        $walk = function (string $path, string $prefix = '') use (&$walk, &$entries, &$bytes, &$files, &$caseKeys, $requireReadOnly) {
            $iterator = new FilesystemIterator($path, FilesystemIterator::SKIP_DOTS);
            foreach ($iterator as $entry) {
                /** @var SplFileInfo $entry */
                $relative = $prefix.$entry->getFilename();
                $absolute = $entry->getPathname();
                $key = strtolower($relative);
                if (++$entries > self::MAX_ENTRIES || !self::validRelativePath($relative)
                    || isset($caseKeys[$key]) || self::canonicalPath($absolute) === null
                    || ($requireReadOnly && is_writable($absolute))) {
                    throw new RuntimeException('Invalid plugin package entry.');
                }
                $caseKeys[$key] = true;

                if ($entry->isDir()) {
                    $walk($absolute, $relative.'/');
                    continue;
                }

                $stat = @stat($absolute);
                if (!$entry->isFile() || $stat === false || $stat['nlink'] !== 1
                    || ($bytes += $stat['size']) > self::MAX_BYTES) {
                    throw new RuntimeException('Invalid plugin package file.');
                }

                $sha256 = @hash_file('sha256', $absolute);
                if ($sha256 === false) {
                    throw new RuntimeException('Cannot read plugin package file.');
                }
                $files[$relative] = $sha256;
            }
        };
        $walk($directory);

        ksort($files, SORT_STRING);
        $tree = hash_init('sha256');
        hash_update($tree, "blessing-skin-plugin-tree-v1\n");
        foreach ($files as $file => $digest) {
            hash_update($tree, $digest.'  '.$file."\n");
        }

        return ['sha256' => hash_final($tree), 'files' => $files];
    }
}
