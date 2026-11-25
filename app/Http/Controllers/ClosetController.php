<?php

namespace App\Http\Controllers;

use App\Models\Texture;
use App\Models\User;
use App\Services\Textures\TextureReferences;
use Blessing\Filter;
use Blessing\Rejection;
use Illuminate\Contracts\Events\Dispatcher;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class ClosetController extends Controller
{
    public function index(Filter $filter)
    {
        $grid = [
            'layout' => [
                ['md-8', 'md-4'],
            ],
            'widgets' => [
                [
                    [
                        'user.widgets.email-verification',
                        'user.widgets.closet.list',
                    ],
                    ['shared.previewer'],
                ],
            ],
        ];
        $grid = $filter->apply('grid:user.closet', $grid);

        return view('user.closet')
            ->with('grid', $grid)
            ->with('extra', [
                'unverified' => option('require_verification') && !auth()->user()->verified,
                'rule' => trans('user.player.player-name-rule.'.option('player_name_rule')),
                'length' => trans(
                    'user.player.player-name-length',
                    ['min' => option('player_name_length_min'), 'max' => option('player_name_length_max')]
                ),
            ]);
    }

    public function getClosetData(Request $request, TextureReferences $references)
    {
        $category = $request->input('category', 'skin');
        $user = $request->user();

        return $this->visibleCloset($user, $references)
            ->when(
                $category === 'cape',
                fn (Builder $query) => $query->where('textures.type', 'cape'),
                fn (Builder $query) => $query->whereIn('textures.type', ['steve', 'alex']),
            )
            ->when(
                $request->input('q'),
                fn (Builder $query, $search) => $query->like('item_name', $search)
            )
            ->orderBy('texture_tid', 'DESC')
            ->paginate(max(1, min(100, (int) $request->input('perPage', 6))));
    }

    public function allIds(Request $request, TextureReferences $references)
    {
        return $this->visibleCloset($request->user(), $references)->pluck('texture_tid');
    }

    private function visibleCloset(User $user, TextureReferences $references): BelongsToMany
    {
        return $user->closet()->where(fn (Builder $query) => $references->visibleTo($query, $user));
    }

    public function add(Request $request, Dispatcher $dispatcher, Filter $filter, TextureReferences $references)
    {
        ['tid' => $tid, 'name' => $name] = $request->validate([
            'tid' => 'required|integer|min:1',
            'name' => 'required|string|max:255',
        ]);
        $uid = $request->user()->uid;
        $name = $this->checkedName($filter->apply('add_closet_item_name', $name, [$tid]));

        return DB::transaction(function () use ($tid, $name, $uid, $dispatcher, $filter, $references) {
            $texture = Texture::lockForUpdate()->find($tid);
            $users = User::whereIn('uid', [$uid, $texture?->uploader])->orderBy('uid')->lockForUpdate()->get()->keyBy('uid');
            $user = $users->get($uid);
            abort_unless($user && $user->permission !== User::BANNED, 403);
            $dispatcher->dispatch('closet.adding', [$tid, $name, $user]);
            $can = $filter->apply('can_add_closet_item', true, [$tid, $name]);
            if ($can instanceof Rejection) {
                return json($can->getReason(), 1);
            }
            $cost = (int) option('score_per_closet_item');
            if ($user->score < $cost) {
                return json(trans('user.closet.add.lack-score'), 1);
            }
            if (!$texture) {
                return json(trans('user.closet.add.not-found'), 1);
            }
            if (!$references->canView($user, $texture)) {
                return json(trans('skinlib.show.private'), 1);
            }
            if (!$references->canKeepInCloset($user, $texture)) {
                return json(trans('user.closet.add.not-found'), 1);
            }
            if ($user->closet()->whereKey($tid)->exists()) {
                return json(trans('user.closet.add.repeated'), 1);
            }
            $user->closet()->attach($tid, ['item_name' => $name]);
            $user->decrement('score', $cost);
            $texture->increment('likes');
            $dispatcher->dispatch('closet.added', [$texture, $name, $user]);
            $uploader = $users->get($texture->uploader);
            if ($uploader && $uploader->uid !== $uid) {
                $uploader->increment('score', (int) option('score_award_per_like', 0));
            }

            return json(trans('user.closet.add.success', ['name' => $name]), 0);
        });
    }

    public function rename(Request $request, Dispatcher $dispatcher, Filter $filter, $tid)
    {
        $name = $request->validate(['name' => 'required|string|max:255'])['name'];
        $uid = $request->user()->uid;
        $name = $this->checkedName($filter->apply('rename_closet_item_name', $name, [$tid]));

        return DB::transaction(function () use ($tid, $uid, $name, $dispatcher, $filter) {
            $texture = Texture::lockForUpdate()->find($tid);
            $user = User::lockForUpdate()->findOrFail($uid);
            $dispatcher->dispatch('closet.renaming', [$tid, $name, $user]);
            $item = $texture ? $user->closet()->find($tid) : null;
            if (!$item) {
                return json(trans('user.closet.remove.non-existent'), 1);
            }
            $previousName = $item->pivot->item_name;
            $can = $filter->apply('can_rename_closet_item', true, [$item, $name]);
            if ($can instanceof Rejection) {
                return json($can->getReason(), 1);
            }
            $user->closet()->updateExistingPivot($tid, ['item_name' => $name]);
            $dispatcher->dispatch('closet.renamed', [$item, $previousName, $user]);

            return json(trans('user.closet.rename.success', ['name' => $name]), 0);
        });
    }

    public function remove(Request $request, Dispatcher $dispatcher, Filter $filter, $tid)
    {
        $uid = $request->user()->uid;

        return DB::transaction(function () use ($tid, $uid, $dispatcher, $filter) {
            $texture = Texture::lockForUpdate()->find($tid);
            $users = User::whereIn('uid', [$uid, $texture?->uploader])->orderBy('uid')->lockForUpdate()->get()->keyBy('uid');
            $user = $users->get($uid);
            abort_unless($user, 404);
            $dispatcher->dispatch('closet.removing', [$tid, $user]);
            $item = $texture ? $user->closet()->find($tid) : null;
            if (!$item) {
                return json(trans('user.closet.remove.non-existent'), 1);
            }
            $can = $filter->apply('can_remove_closet_item', true, [$item]);
            if ($can instanceof Rejection) {
                return json($can->getReason(), 1);
            }
            $user->closet()->detach($tid);
            if (option('return_score')) {
                $user->increment('score', (int) option('score_per_closet_item'));
            }
            if ($texture->likes > 0) {
                $texture->decrement('likes');
            }
            $dispatcher->dispatch('closet.removed', [$texture, $user]);
            $uploader = $users->get($texture->uploader);
            if ($uploader && $uploader->uid !== $uid) {
                $uploader->decrement('score', (int) option('score_award_per_like', 0));
            }

            return json(trans('user.closet.remove.success'), 0);
        });
    }

    private function checkedName(mixed $name): string
    {
        if (!is_string($name) || trim($name) === '' || mb_strlen($name) > 255) {
            throw ValidationException::withMessages(['name' => trans('validation.string', ['attribute' => 'name'])]);
        }

        return $name;
    }
}
