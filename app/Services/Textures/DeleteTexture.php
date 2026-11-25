<?php

namespace App\Services\Textures;

use App\Models\Texture;
use Illuminate\Contracts\Events\Dispatcher;
use Illuminate\Support\Facades\DB;

final class DeleteTexture
{
    public function __construct(private TextureBlobs $blobs, private Dispatcher $events)
    {
    }

    /** Ordinary deletion and moderation both remove exactly one reference. */
    public function handle(Texture $texture): bool
    {
        return DB::transaction(function () use ($texture) {
            if (TextureBlobs::validHash($texture->hash)) {
                $this->blobs->lock($texture->hash);
            }
            $fresh = Texture::lockForUpdate()->find($texture->tid);
            if (!$fresh) {
                return false;
            }
            $this->events->dispatch('texture.deleting', [$fresh]);
            if (!$fresh->delete()) {
                return false;
            }
            $this->events->dispatch('texture.deleted', [$fresh]);
            $this->blobs->reconcile($fresh->hash);

            return true;
        });
    }
}
