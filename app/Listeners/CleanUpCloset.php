<?php

namespace App\Listeners;

use App\Models\Texture;
use App\Models\User;
use Illuminate\Support\Facades\DB;

class CleanUpCloset
{
    public function handle(Texture $texture): void
    {
        if ($texture->exists && $texture->public) {
            return;
        }

        $pivots = DB::table('user_closet')->where('texture_tid', $texture->tid);
        if ($texture->exists) {
            $pivots->where('user_uid', '!=', $texture->uploader);
        }
        $otherUids = (clone $pivots)->where('user_uid', '!=', $texture->uploader)->pluck('user_uid')->unique();
        $count = $pivots->delete();
        if (option('return_score') && $otherUids->isNotEmpty()) {
            User::whereIn('uid', $otherUids)->increment('score', (int) option('score_per_closet_item'));
        }
        if ($texture->exists && $count > 0) {
            $texture->likes = max(0, $texture->likes - $count);
            $texture->save();
        }
    }
}
