<?php

namespace App\Listeners;

use App\Models\Texture;
use App\Models\User;
use App\Services\Textures\TextureReferences;

class ResetPlayerForRemovedClosetItem
{
    public function handle(Texture $texture, User $user): void
    {
        app(TextureReferences::class)->detachSlots($texture, $user);
    }
}
