<?php

namespace App\Services\Textures;

use Closure;
use Illuminate\Contracts\Cache\LockProvider;
use Illuminate\Support\Facades\Cache;
use Symfony\Component\HttpKernel\Exception\ServiceUnavailableHttpException;

final class TextureWorkBudget
{
    private const PERMITS = 4;
    private const LEASE_SECONDS = 20;

    public function run(Closure $work): mixed
    {
        $store = Cache::getStore();
        if (!$store instanceof LockProvider) {
            throw new ServiceUnavailableHttpException(1, 'Texture processing requires a lock-capable cache store.');
        }
        for ($index = 0; $index < self::PERMITS; ++$index) {
            $permit = $store->lock('rendered-textures:permit:'.$index, self::LEASE_SECONDS);
            if (!$permit->get()) {
                continue;
            }
            try {
                return $work();
            } finally {
                $permit->release();
            }
        }

        throw new ServiceUnavailableHttpException(1, 'Texture processing is busy.');
    }
}
