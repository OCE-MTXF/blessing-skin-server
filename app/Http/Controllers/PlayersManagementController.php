<?php

namespace App\Http\Controllers;

use App\Models\Player;
use App\Models\Texture;
use App\Models\User;
use App\Rules;
use App\Services\Textures\TextureReferences;
use Illuminate\Contracts\Events\Dispatcher;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

class PlayersManagementController extends Controller
{
    public function __construct()
    {
        $this->middleware(function (Request $request, $next) {
            /** @var Player */
            $player = $request->route('player');
            $owner = $player->user;

            /** @var User */
            $currentUser = $request->user();

            if (
                $owner && $owner->uid !== $currentUser->uid
                && $owner->permission >= $currentUser->permission
            ) {
                return json(trans('admin.players.no-permission'), 1)
                    ->setStatusCode(403);
            }

            return $next($request);
        })->except(['list']);
    }

    public function list(Request $request)
    {
        $query = $request->query('q');

        return Player::usingSearchString($query)->paginate(10);
    }

    public function name(
        Player $player,
        Request $request,
        Dispatcher $dispatcher,
    ) {
        $name = $request->validate([
            'player_name' => [
                'required',
                new Rules\PlayerName(),
                'min:'.option('player_name_length_min'),
                'max:'.option('player_name_length_max'),
                Rule::unique('players', 'name')->ignoreModel($player),
            ],
        ])['player_name'];

        return DB::transaction(function () use ($player, $dispatcher, $name, $request) {
            $player = Player::lockForUpdate()->findOrFail($player->pid);
            $this->authorizeOwner($request->user(), $player->user);
            $dispatcher->dispatch('player.renaming', [$player, $name]);
            $oldName = $player->name;
            $player->name = $name;
            try {
                $player->save();
            } catch (UniqueConstraintViolationException) {
                throw ValidationException::withMessages(['player_name' => trans('validation.unique', ['attribute' => 'player name'])]);
            }
            $dispatcher->dispatch('player.renamed', [$player, $oldName]);

            return json(trans('admin.players.name.success', ['player' => $player->name]), 0);
        });
    }

    public function owner(
        Player $player,
        Request $request,
        Dispatcher $dispatcher,
    ) {
        $uid = (int) $request->validate(['uid' => 'required|integer'])['uid'];

        return DB::transaction(function () use ($player, $uid, $request, $dispatcher) {
            $user = User::lockForUpdate()->find($uid);
            $player = Player::lockForUpdate()->findOrFail($player->pid);
            $this->authorizeOwner($request->user(), $player->user);
            $dispatcher->dispatch('player.owner.updating', [$player, $uid]);
            if (!$user) {
                return json(trans('admin.users.operations.non-existent'), 1);
            }
            $this->authorizeOwner($request->user(), $user);
            if ($player->uid !== $uid) {
                // Transfers grant neither the old owner's closet rights nor public profile attachments.
                $player->tid_skin = $player->tid_cape = 0;
                $player->uid = $uid;
                $player->save();
            }
            $dispatcher->dispatch('player.owner.updated', [$player, $user]);

            return json(trans('admin.players.owner.success', ['player' => $player->name, 'user' => $user->nickname]), 0, [
                'tid_skin' => $player->tid_skin,
                'tid_cape' => $player->tid_cape,
            ]);
        });
    }

    public function texture(
        Player $player,
        Request $request,
        Dispatcher $dispatcher,
        TextureReferences $references,
    ) {
        $data = $request->validate([
            'tid' => 'required|integer',
            'type' => ['required', Rule::in(['skin', 'cape'])],
        ]);
        $tid = (int) $data['tid'];
        $type = $data['type'];

        return DB::transaction(function () use ($player, $tid, $type, $dispatcher, $references, $request) {
            $texture = $tid > 0 ? Texture::lockForUpdate()->find($tid) : null;
            $owner = User::lockForUpdate()->find($player->uid);
            $fresh = Player::lockForUpdate()->findOrFail($player->pid);
            abort_if($fresh->uid !== $player->uid, 409, 'The player owner changed. Reload and try again.');
            $this->authorizeOwner($request->user(), $owner);
            $dispatcher->dispatch('player.texture.updating', [$fresh, $type, $tid]);
            if ($tid !== 0 && (!$texture || !$owner || !$references->accepts($owner, $texture, $type))) {
                return json(trans('admin.players.textures.non-existent', ['tid' => $tid]), 1);
            }
            $field = 'tid_'.$type;
            $previousTid = $fresh->$field;
            $fresh->$field = $tid;
            $fresh->save();
            $dispatcher->dispatch('player.texture.updated', [$fresh, $type, $previousTid]);

            return json(trans('admin.players.textures.success', ['player' => $fresh->name]), 0);
        });
    }

    private function authorizeOwner(User $actor, ?User $owner): void
    {
        abort_if($owner && $owner->uid !== $actor->uid && $owner->permission >= $actor->permission, 403, trans('admin.players.no-permission'));
    }

    public function delete(
        Player $player,
        Dispatcher $dispatcher,
    ) {
        $dispatcher->dispatch('player.deleting', [$player]);

        $player->delete();

        $dispatcher->dispatch('player.deleted', [$player]);

        return json(trans('admin.players.delete.success'), 0);
    }
}
