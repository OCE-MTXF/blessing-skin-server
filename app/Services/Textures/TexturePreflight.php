<?php

namespace App\Services\Textures;

use App\Models\User;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\DB;

final class TexturePreflight
{
    private const SLOTS = [
        'skin' => ['players', 'tid_skin'],
        'cape' => ['players', 'tid_cape'],
        'avatar' => ['users', 'avatar'],
    ];

    public function __construct(private TextureBlobs $blobs, private TextureImages $images) {}

    /** A read-only report without hashes, TIDs, usernames, file paths, or image content. */
    public function counts(): array
    {
        $counts = array_fill_keys([
            'texture_records', 'private_records', 'public_with_unavailable_owner', 'supported_png', 'supported_webp',
            'invalid_digest', 'missing_or_unreadable', 'digest_mismatch', 'invalid_image', 'incompatible_layout',
            'unavailable_skin_references', 'unavailable_cape_references', 'unavailable_avatar_references',
        ], 0);
        foreach (self::SLOTS as $slot => [$table, $field]) {
            $counts['unavailable_'.$slot.'_references'] = DB::table($table.' as holder')->where('holder.'.$field, '!=', 0)
                ->whereNotExists(fn (Builder $query) => $query->selectRaw('1')->from('textures')
                    ->whereColumn('textures.tid', 'holder.'.$field))->count();
        }

        DB::table('textures')->leftJoin('users as owner', 'owner.uid', 'textures.uploader')
            ->select(['textures.tid', 'textures.hash', 'textures.type', 'textures.public', 'owner.permission'])
            ->chunkById(100, function ($textures) use (&$counts) {
                $ids = [];
                $playable = array_fill_keys(array_keys(self::SLOTS), []);
                foreach ($textures as $texture) {
                    $ids[] = $texture->tid;
                    ++$counts['texture_records'];
                    $public = (bool) $texture->public;
                    $ownerAvailable = $texture->permission !== null && (int) $texture->permission !== User::BANNED;
                    if (!$public) {
                        ++$counts['private_records'];
                    } elseif (!$ownerAvailable) {
                        ++$counts['public_with_unavailable_owner'];
                    }
                    [$status, $source] = $this->blobs->probe($texture->hash);
                    if (!$source) {
                        ++$counts[$status];
                        continue;
                    }
                    if (!$this->images->matches($source, $texture->type)) {
                        ++$counts['incompatible_layout'];
                        continue;
                    }
                    ++$counts['supported_'.$source->format];
                    if ($public && $ownerAvailable) {
                        foreach ($texture->type === 'cape' ? ['cape'] : ['skin', 'avatar'] as $slot) {
                            $playable[$slot][] = $texture->tid;
                        }
                    }
                }
                foreach (self::SLOTS as $slot => [$table, $field]) {
                    $column = 'holder.'.$field;
                    $counts['unavailable_'.$slot.'_references'] += DB::table($table.' as holder')
                        ->leftJoin('users as user_account', 'user_account.uid', 'holder.uid')
                        ->whereIn($column, $ids)
                        ->where(fn (Builder $query) => $query->whereNotIn($column, $playable[$slot])
                            ->orWhereNull('user_account.uid')->orWhere('user_account.permission', User::BANNED)
                            ->orWhereNotExists(fn (Builder $closet) => $closet->selectRaw('1')->from('user_closet')
                                ->whereColumn('user_closet.user_uid', 'holder.uid')->whereColumn('user_closet.texture_tid', $column)))
                        ->count();
                }
            }, 'textures.tid', 'tid');

        return $counts;
    }
}
