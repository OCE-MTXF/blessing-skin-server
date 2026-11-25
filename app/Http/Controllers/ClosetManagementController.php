<?php

namespace App\Http\Controllers;

use App\Models\Texture;
use App\Models\User;
use App\Services\Textures\TextureReferences;
use Illuminate\Contracts\Events\Dispatcher;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class ClosetManagementController extends Controller
{
    public function list(User $user)
    {
        return $user->closet;
    }

    public function add(Request $request, Dispatcher $dispatcher, User $user, TextureReferences $references)
    {
        $tid = $request->validate(['tid' => 'required|integer|min:1'])['tid'];

        return DB::transaction(function () use ($tid, $user, $dispatcher, $references) {
            $texture = Texture::lockForUpdate()->find($tid);
            $user = User::lockForUpdate()->findOrFail($user->uid);
            // Staff can inspect private textures but cannot grant them to other accounts.
            if (!$texture || !$references->canKeepInCloset($user, $texture)) {
                return json(trans('user.closet.add.not-found'), 1);
            }
            if ($user->closet()->whereKey($tid)->exists()) {
                return json(trans('user.closet.add.repeated'), 1);
            }
            $name = $texture->name;
            $dispatcher->dispatch('closet.adding', [$tid, $name, $user]);
            $user->closet()->attach($tid, ['item_name' => $name]);
            $dispatcher->dispatch('closet.added', [$texture, $name, $user]);

            return json('', 0, compact('user', 'texture'));
        });
    }

    public function remove(Request $request, Dispatcher $dispatcher, User $user)
    {
        $tid = $request->validate(['tid' => 'required|integer|min:1'])['tid'];

        return DB::transaction(function () use ($tid, $user, $dispatcher) {
            $texture = Texture::lockForUpdate()->find($tid);
            $user = User::lockForUpdate()->findOrFail($user->uid);
            $dispatcher->dispatch('closet.removing', [$tid, $user]);
            if (!$texture || !$user->closet()->detach($tid)) {
                return json(trans('user.closet.remove.non-existent'), 1);
            }
            $dispatcher->dispatch('closet.removed', [$texture, $user]);

            return json('', 0, compact('user', 'texture'));
        });
    }
}
