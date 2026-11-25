<?php

namespace App\Listeners;

use App\Models\Texture;
use App\Services\Textures\TextureReferences;

class ResetPlayers
{
    public function handle(Texture $texture): void
    {
        if (!$texture->exists || !$texture->public) {
            app(TextureReferences::class)->detachSlots($texture);
        }
    }
}
