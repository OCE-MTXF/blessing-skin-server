<?php

namespace App\Services\Textures;

use App\Models\Player;
use App\Models\Texture;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;

final class TextureReferences
{
    public function __construct(private TextureBlobs $blobs)
    {
    }

    public function isPublished(Texture $texture): bool
    {
        return $texture->public && $texture->owner !== null && $texture->owner->permission !== User::BANNED;
    }

    public function canView(?User $actor, Texture $texture): bool
    {
        return $this->isPublished($texture) || ($actor && $actor->permission !== User::BANNED
            && ($actor->uid === $texture->uploader || $actor->isAdmin()));
    }

    public function visibleTo(Builder $query, ?User $actor): Builder
    {
        return $query->where(function (Builder $query) use ($actor) {
            $query->published();
            if ($actor && $actor->permission !== User::BANNED) {
                if ($actor->isAdmin()) {
                    $query->orWhereNotNull('textures.tid');
                } else {
                    $query->orWhere('textures.uploader', $actor->uid);
                }
            }
        });
    }

    public function canKeepInCloset(User $user, Texture $texture): bool
    {
        return $user->permission !== User::BANNED
            && ($texture->uploader === $user->uid || $this->isPublished($texture))
            && $this->blobs->has($texture);
    }

    public function accepts(User $user, Texture $texture, string $slot): bool
    {
        return $user->permission !== User::BANNED
            && $texture->fitsSlot($slot)
            && $this->isPublished($texture)
            && $user->closet()->where('textures.tid', $texture->tid)->exists()
            && $this->blobs->has($texture);
    }

    public function gameTexture(?User $user, ?Texture $texture, string $slot): ?Texture
    {
        return $user && $texture && $this->accepts($user, $texture, $slot) ? $texture : null;
    }

    public function detachSlots(Texture $texture, ?User $user = null, bool $onlyIncompatible = false): void
    {
        $players = Player::query();
        $users = User::query();
        if ($user) {
            $players->where('uid', $user->uid);
            $users->where('uid', $user->uid);
        }
        if (!$onlyIncompatible || !$texture->fitsSlot('avatar')) {
            $users->where('avatar', $texture->tid)->update(['avatar' => 0]);
        }
        foreach (['skin', 'cape'] as $slot) {
            if (!$onlyIncompatible || !$texture->fitsSlot($slot)) {
                $field = 'tid_'.$slot;
                (clone $players)->where($field, $texture->tid)->update([$field => 0]);
            }
        }
    }
}
