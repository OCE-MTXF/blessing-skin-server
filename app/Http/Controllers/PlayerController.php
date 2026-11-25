<?php

namespace App\Http\Controllers;

use App\Events\PlayerWasAdded;
use App\Events\PlayerWasDeleted;
use App\Events\PlayerWillBeAdded;
use App\Events\PlayerWillBeDeleted;
use App\Models\Player;
use App\Models\Texture;
use App\Models\User;
use App\Rules;
use App\Services\Textures\TextureReferences;
use Blessing\Filter;
use Blessing\Rejection;
use Illuminate\Contracts\Events\Dispatcher;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

class PlayerController extends Controller
{
    public function __construct()
    {
        $this->middleware(function (Request $request, $next) {
            /** @var Player */
            $player = $request->route('player');
            if ($player->uid !== $request->user()?->uid) {
                return json(trans('admin.players.no-permission'), 1)
                    ->setStatusCode(403);
            }

            return $next($request);
        }, [
            'only' => ['delete', 'rename', 'setTexture', 'clearTexture'],
        ]);
    }

    public function index(Filter $filter)
    {
        $grid = [
            'layout' => [
                ['md-6', 'md-6'],
            ],
            'widgets' => [
                [
                    [
                        'user.widgets.players.list',
                        'user.widgets.players.notice',
                    ],
                    ['shared.previewer'],
                ],
            ],
        ];
        $grid = $filter->apply('grid:user.player', $grid);

        /** @var User */
        $user = auth()->user();

        return view('user.player')
            ->with('grid', $grid)
            ->with('extra', [
                'count' => $user->players()->count(),
                'rule' => trans('user.player.player-name-rule.'.option('player_name_rule')),
                'length' => trans(
                    'user.player.player-name-length',
                    ['min' => option('player_name_length_min'), 'max' => option('player_name_length_max')]
                ),
                'score' => auth()->user()->score,
                'cost' => (int) option('score_per_player'),
            ]);
    }

    public function list()
    {
        return Auth::user()->players;
    }

    public function add(Request $request, Dispatcher $dispatcher, Filter $filter)
    {
        /** @var User */
        $user = Auth::user();

        $name = $request->validate([
            'name' => [
                'required',
                new Rules\PlayerName(),
                'min:'.option('player_name_length_min'),
                'max:'.option('player_name_length_max'),
                'unique:players',
            ],
        ])['name'];
        $name = $filter->apply('new_player_name', $name);

        $dispatcher->dispatch('player.add.attempt', [$name, $user]);

        $can = $filter->apply('can_add_player', true, [$name]);
        if ($can instanceof Rejection) {
            return json($can->getReason(), 1);
        }

        return DB::transaction(function () use ($user, $name, $dispatcher) {
            $user = User::lockForUpdate()->findOrFail($user->uid);
            $cost = (int) option('score_per_player');
            if ($user->score < $cost) {
                return json(trans('user.player.add.lack-score'), 7);
            }
            $dispatcher->dispatch('player.adding', [$name, $user]);
            event(new PlayerWillBeAdded($name));
            $player = new Player();
            $player->uid = $user->uid;
            $player->name = $name;
            $player->tid_skin = 0;
            $player->tid_cape = 0;
            $this->saveWithUniqueName($player);
            $user->decrement('score', $cost);
            $dispatcher->dispatch('player.added', [$player, $user]);
            event(new PlayerWasAdded($player));

            return json(trans('user.player.add.success', ['name' => $name]), 0, $player->toArray());
        });
    }

    public function delete(
        Dispatcher $dispatcher,
        Filter $filter,
        Player $player,
    ) {
        /** @var User */
        $user = auth()->user();
        $playerName = $player->name;

        $dispatcher->dispatch('player.delete.attempt', [$player, $user]);

        $can = $filter->apply('can_delete_player', true, [$player]);
        if ($can instanceof Rejection) {
            return json($can->getReason(), 1);
        }

        $dispatcher->dispatch('player.deleting', [$player, $user]);
        event(new PlayerWillBeDeleted($player));

        $player->delete();

        if (option('return_score')) {
            $user->score += (int) option('score_per_player');
            $user->save();
        }

        $dispatcher->dispatch('player.deleted', [$player, $user]);
        event(new PlayerWasDeleted($playerName));

        return json(trans('user.player.delete.success', ['name' => $playerName]), 0);
    }

    public function rename(
        Request $request,
        Dispatcher $dispatcher,
        Filter $filter,
        Player $player,
    ) {
        $name = $request->validate([
            'name' => [
                'required',
                new Rules\PlayerName(),
                'min:'.option('player_name_length_min'),
                'max:'.option('player_name_length_max'),
                Rule::unique('players')->ignoreModel($player),
            ],
        ])['name'];
        $name = $filter->apply('new_player_name', $name);

        $dispatcher->dispatch('player.renaming', [$player, $name]);

        $can = $filter->apply('can_rename_player', true, [$player, $name]);
        if ($can instanceof Rejection) {
            return json($can->getReason(), 1);
        }

        return DB::transaction(function () use ($player, $request, $dispatcher, $name) {
            $player = Player::lockForUpdate()->findOrFail($player->pid);
            abort_unless($player->uid === $request->user()->uid, 403);
            $old = $player->replicate();
            $player->name = $name;
            $this->saveWithUniqueName($player);
            $dispatcher->dispatch('player.renamed', [$player, $old]);

            return json(
                trans('user.player.rename.success', ['old' => $old->name, 'new' => $name]),
                0,
                $player->toArray()
            );
        });
    }

    private function saveWithUniqueName(Player $player): void
    {
        try {
            $player->save();
        } catch (UniqueConstraintViolationException) {
            throw ValidationException::withMessages(['name' => trans('validation.unique', ['attribute' => 'name'])]);
        }
    }

    public function setTexture(
        Request $request,
        Dispatcher $dispatcher,
        Filter $filter,
        Player $player,
        TextureReferences $references,
    ) {
        $data = $request->validate([
            'skin' => ['nullable', 'integer'],
            'cape' => ['nullable', 'integer'],
        ]);
        $uid = $request->user()->uid;

        return DB::transaction(function () use ($player, $uid, $data, $dispatcher, $filter, $references) {
            // Sort locks when changing two slots, then lock user and player in that order.
            $ids = array_unique(array_map('intval', array_filter($data, fn ($tid) => (int) $tid > 0)));
            $textures = Texture::whereIn('tid', $ids)->orderBy('tid')->lockForUpdate()->get()->keyBy('tid');
            $user = User::lockForUpdate()->findOrFail($uid);
            $player = Player::lockForUpdate()->findOrFail($player->pid);
            abort_unless($player->uid === $uid && $user->permission !== User::BANNED, 403);
            $updates = [];
            foreach (['skin', 'cape'] as $slot) {
                $tid = $data[$slot] ?? null;
                $can = $filter->apply('can_set_texture', true, [$player, $slot, $tid]);
                if ($can instanceof Rejection) {
                    return json($can->getReason(), 1);
                }
                if (!$tid) {
                    continue;
                }
                $texture = $textures->get($tid);
                if (!$texture) {
                    return json(trans('skinlib.non-existent'), 1);
                }
                if (!$references->accepts($user, $texture, $slot)) {
                    return json(trans('user.closet.remove.non-existent'), 1);
                }
                $updates[$slot] = $texture;
            }
            foreach ($updates as $slot => $texture) {
                $dispatcher->dispatch('player.texture.updating', [$player, $texture]);
                $player->{'tid_'.$slot} = $texture->tid;
                $player->save();
                $dispatcher->dispatch('player.texture.updated', [$player, $texture]);
            }

            return json(trans('user.player.set.success', ['name' => $player->name]), 0, $player->toArray());
        });
    }

    public function clearTexture(
        Request $request,
        Dispatcher $dispatcher,
        Filter $filter,
        Player $player,
    ) {
        $data = $request->validate(['type' => ['sometimes', 'array', 'max:2'], 'type.*' => [Rule::in(['skin', 'cape'])]]);

        return DB::transaction(function () use ($player, $request, $data, $filter, $dispatcher) {
            $player = Player::lockForUpdate()->findOrFail($player->pid);
            abort_unless($player->uid === $request->user()->uid, 403);
            $clear = [];
            foreach (['skin', 'cape'] as $slot) {
                $can = $filter->apply('can_clear_texture', true, [$player, $slot]);
                if ($can instanceof Rejection) {
                    return json($can->getReason(), 1);
                }
                if ($request->has($slot) || in_array($slot, $data['type'] ?? [], true)) {
                    $clear[] = $slot;
                }
            }
            foreach ($clear as $slot) {
                $dispatcher->dispatch('player.texture.resetting', [$player, $slot]);
                $player->{'tid_'.$slot} = 0;
                $player->save();
                $dispatcher->dispatch('player.texture.reset', [$player, $slot]);
            }

            return json(trans('user.player.clear.success', ['name' => $player->name]), 0, $player->toArray());
        });
    }
}
