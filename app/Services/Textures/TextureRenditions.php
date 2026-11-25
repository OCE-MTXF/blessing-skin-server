<?php

namespace App\Services\Textures;

use Closure;
use Illuminate\Contracts\Cache\LockProvider;
use Illuminate\Support\Facades\Cache;
use Symfony\Component\HttpKernel\Exception\ServiceUnavailableHttpException;

final class TextureRenditions
{
    private const VERSION = 'gd-v1';
    private const SLOTS = 128;
    private const MAX_ENTRY = 256 * 1024;

    public function __construct(private TextureWorkBudget $budget) {}

    public function get(string $variant, ?TextureImage $source, bool $cache, Closure $render): string
    {
        if (!$cache) {
            return $this->budget->run($render);
        }
        $key = hash('sha256', implode(':', [
            self::VERSION,
            Cache::get('rendered-textures:generation', 'initial'),
            $variant,
            $source ? hash('sha256', $source->bytes) : 'default',
        ]));
        // Direct-mapped cache: generations and source diversity cannot grow the number of data/lock keys.
        $slot = 'rendered-textures:slot:'.(hexdec(substr($key, 0, 4)) % self::SLOTS);
        if (is_array($entry = Cache::get($slot)) && ($entry['key'] ?? null) === $key && is_string($entry['bytes'] ?? null)) {
            return $entry['bytes'];
        }

        $store = Cache::getStore();
        if (!$store instanceof LockProvider) {
            throw new ServiceUnavailableHttpException(1, 'Texture renderer requires a lock-capable cache store.');
        }
        $lock = $store->lock($slot.':lock', 20);
        if (!$lock->get()) {
            throw new ServiceUnavailableHttpException(1, 'Texture rendition is already in progress.');
        }
        try {
            if (is_array($entry = Cache::get($slot)) && ($entry['key'] ?? null) === $key && is_string($entry['bytes'] ?? null)) {
                return $entry['bytes'];
            }

            return $this->budget->run(function () use ($render, $key, $slot) {
                $bytes = $render();
                if (strlen($bytes) <= self::MAX_ENTRY) {
                    Cache::put($slot, ['key' => $key, 'bytes' => $bytes], now()->addMinutes(15));
                }

                return $bytes;
            });
        } finally {
            $lock->release();
        }
    }
}
