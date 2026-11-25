<?php

namespace App\Http\Controllers;

use App\Models\Texture;
use App\Models\User;
use App\Services\SafeMarkdown;
use App\Services\Textures\DeleteTexture;
use App\Services\Textures\InvalidTextureImage;
use App\Services\Textures\TextureBlobs;
use App\Services\Textures\TextureImages;
use App\Services\Textures\TextureReferences;
use App\Services\Textures\TextureWorkBudget;
use Blessing\Filter;
use Blessing\Rejection;
use Illuminate\Contracts\Events\Dispatcher;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Request;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;

class SkinlibController extends Controller
{
    public function __construct(private TextureReferences $references)
    {
        $this->middleware(function (Request $request, $next) {
            $user = $request->user();
            $texture = $request->route('texture');
            if (!$user || $user->permission === User::BANNED || ($texture->uploader !== $user->uid && !$user->isAdmin())) {
                return json(trans('skinlib.no-permission'), 1)->setStatusCode(403);
            }

            return $next($request);
        })->only(['rename', 'privacy', 'type', 'delete']);

        $this->middleware(function (Request $request, $next) {
            if (!$this->references->canView($request->user(), $request->route('texture'))) {
                abort_if((int) option('status_code_for_private') === 404, 404, trans('skinlib.show.deleted'));
                abort(403, trans('skinlib.show.private'));
            }

            return $next($request);
        })->only(['show', 'info']);
    }

    public function library(Request $request)
    {
        $data = $request->validate([
            'filter' => ['nullable', Rule::in(['skin', 'steve', 'alex', 'cape'])],
            'sort' => ['nullable', Rule::in(['time', 'likes'])],
            'uploader' => ['nullable', 'integer'],
            'keyword' => ['nullable', 'string', 'max:255'],
        ]);
        $type = $data['filter'] ?? 'skin';
        $sortBy = ($data['sort'] ?? 'time') === 'likes' ? 'likes' : 'upload_at';

        $textures = $this->references->visibleTo(Texture::query(), $request->user())
            ->orderByDesc($sortBy)->orderByDesc('tid')
            ->when(
                $type === 'skin',
                fn (Builder $query) => $query->whereIn('type', ['steve', 'alex']),
                fn (Builder $query) => $query->where('type', $type),
            )
            ->when($data['keyword'] ?? null, fn (Builder $query, $value) => $query->like('textures.name', $value))
            ->when($data['uploader'] ?? null, fn (Builder $query, $value) => $query->where('textures.uploader', $value))
            ->join('users as uploaders', 'uploaders.uid', 'textures.uploader')
            ->select(['tid', 'textures.name', 'type', 'textures.uploader', 'public', 'likes', 'uploaders.nickname'])
            ->paginate(20);

        return response()->json($textures)->header('Cache-Control', 'private, no-store');
    }

    public function show(Filter $filter, Texture $texture, TextureBlobs $blobs)
    {
        $user = auth()->user();
        abort_unless($blobs->has($texture), 404, trans('skinlib.show.deleted'));
        $badges = [];
        $uploader = $texture->owner;
        if ($uploader) {
            if ($uploader->isAdmin()) {
                $badges[] = ['text' => 'STAFF', 'color' => 'primary'];
            }

            $badges = $filter->apply('user_badges', $badges, [$uploader]);
        }

        $grid = $filter->apply('grid:skinlib.show', [
            'layout' => [['md-8', 'md-4']],
            'widgets' => [[['shared.previewer'], ['skinlib.widgets.show.side']]],
        ]);

        return response()->view('skinlib.show', [
            'texture' => $texture,
            'grid' => $grid,
            'extra' => [
                'download' => (bool) option('allow_downloading_texture'),
                'currentUid' => $user ? $user->uid : 0,
                'admin' => $user && $user->isAdmin(),
                'inCloset' => $user && $user->closet()->where('tid', $texture->tid)->exists(),
                'uploaderExists' => (bool) $uploader,
                'nickname' => $uploader?->nickname ?? trans('general.unexistent-user'),
                'report' => (int) option('reporter_score_modification', 0),
                'badges' => $badges,
            ],
        ])->header('Cache-Control', 'private, no-store');
    }

    public function info(Texture $texture)
    {
        return response()->json($texture)->header('Cache-Control', 'private, no-store');
    }

    public function upload(Filter $filter, SafeMarkdown $markdown)
    {
        $grid = $filter->apply('grid:skinlib.upload', [
            'layout' => [['md-6', 'md-6']],
            'widgets' => [[['skinlib.widgets.upload.input'], ['shared.previewer']]],
        ]);

        return view('skinlib.upload')
            ->with('grid', $grid)
            ->with('extra', [
                'rule' => ($regexp = option('texture_name_regexp'))
                    ? trans('skinlib.upload.name-rule-regexp', compact('regexp'))
                    : trans('skinlib.upload.name-rule'),
                'privacyNotice' => trans('skinlib.upload.private-score-notice', ['score' => option('private_score_per_storage')]),
                'score' => (int) auth()->user()->score,
                'scorePublic' => (int) option('score_per_storage'),
                'scorePrivate' => (int) option('private_score_per_storage'),
                'closetItemCost' => (int) option('score_per_closet_item'),
                'award' => (int) option('score_award_per_texture'),
                'contentPolicy' => $markdown->render((string) option_localized('content_policy')),
            ]);
    }

    public function handleUpload(
        Request $request, Filter $filter, Dispatcher $events, TextureImages $images, TextureBlobs $blobs, TextureWorkBudget $budget,
    ) {
        $byteLimit = min(intdiv(TextureImages::MAX_BYTES, 1024), max(1, (int) option('max_upload_file_size', 4096))) * 1024;
        $data = $request->validate([
            'name' => $this->nameRules(),
            'file' => ['required', 'file', 'max:'.intdiv($byteLimit, 1024)],
            'type' => ['required', Rule::in(['steve', 'alex', 'cape'])],
            'public' => ['required', 'boolean'],
        ]);
        $file = $filter->apply('uploaded_texture_file', $data['file']);
        abort_unless($file instanceof UploadedFile && $file->getSize() <= $byteLimit, 422);
        $name = $filter->apply('uploaded_texture_name', $data['name'], [$file]);
        abort_unless(is_string($name) && mb_strlen($name) <= 50 && $name !== '', 422);
        $can = $filter->apply('can_upload_texture', true, [$file, $name]);
        if ($can instanceof Rejection) {
            return json($can->getReason(), 1);
        }

        $type = $data['type'];
        try {
            $normalized = $budget->run(function () use ($images, $file, $type) {
                $source = $images->uploaded($file);
                $maxWidth = min(TextureImages::MAX_EDGE, max(1, (int) option('max_texture_width', TextureImages::MAX_EDGE)));
                if ($source->width > $maxWidth) {
                    return json(trans('skinlib.upload.too-wide', ['width' => $source->width, 'maxWidth' => $maxWidth]), 1);
                }
                if (!$images->matches($source, $type)) {
                    return $this->invalidSize($type, $source->width, $source->height);
                }
                // Normalize all new uploads to lossless PNG. Legacy blobs keep their original format.
                $image = $images->decode($source);

                return [$image, $images->inspect($images->encode($image, 'png'))->decodeBytes];
            });
        } catch (InvalidTextureImage) {
            throw \Illuminate\Validation\ValidationException::withMessages(['file' => [trans('validation.image', ['attribute' => 'file'])]]);
        }
        if (!is_array($normalized)) {
            return $normalized;
        }
        [$image, $sanitized] = $normalized;
        $hash = hash('sha256', $sanitized);
        // Keep the plugin hook observable, but never let it override the canonical storage key.
        $filter->apply('uploaded_texture_hash', $hash, [$image]);
        $fileSize = (int) ceil(strlen($sanitized) / 1024);
        $isPublic = (bool) $data['public'];
        $cost = $fileSize * (int) option($isPublic ? 'score_per_storage' : 'private_score_per_storage')
            + (int) option('score_per_closet_item') - (int) option('score_award_per_texture', 0);
        $uid = $request->user()->uid;
        $blobs->reserve($hash);

        return DB::transaction(function () use ($blobs, $events, $uid, $hash, $name, $type, $fileSize, $isPublic, $cost, $sanitized, $image) {
            $blobs->lock($hash);
            $user = User::lockForUpdate()->findOrFail($uid);
            abort_if($user->permission === User::BANNED, 403);
            $duplicate = Texture::where('hash', $hash)
                ->where(fn (Builder $query) => $query->published()->orWhere('textures.uploader', $uid))
                ->first();
            if ($duplicate) {
                return json(trans('skinlib.upload.repeated'), 2, ['tid' => $duplicate->tid]);
            }
            if ($user->score < $cost) {
                return json(trans('skinlib.upload.lack-score'), 1);
            }
            $events->dispatch('texture.uploading', [$image, $name, $hash]);
            $blobs->publish($hash, $sanitized);

            $texture = new Texture();
            $texture->name = $name;
            $texture->type = $type;
            $texture->hash = $hash;
            $texture->size = $fileSize;
            $texture->public = $isPublic;
            $texture->uploader = $uid;
            $texture->likes = 1;
            if (!$texture->save()) {
                throw new \RuntimeException('Texture record creation was cancelled.');
            }
            $user->score -= $cost;
            $user->closet()->attach($texture->tid, ['item_name' => $name]);
            if (!$user->save()) {
                throw new \RuntimeException('Texture upload score update was cancelled.');
            }
            $blobs->reconcile($hash);
            $events->dispatch('texture.uploaded', [$texture, $image]);

            return json(trans('skinlib.upload.success', ['name' => $name]), 0, ['tid' => $texture->tid]);
        });
    }

    public function delete(Texture $texture, DeleteTexture $delete, Filter $filter)
    {
        $can = $filter->apply('can_delete_texture', true, [$texture]);
        if ($can instanceof Rejection) {
            return json($can->getReason(), 1);
        }
        abort_unless($delete->handle($texture), 404);

        return json(trans('skinlib.delete.success'), 0);
    }

    public function privacy(Request $request, Texture $texture, Dispatcher $events, Filter $filter, TextureBlobs $blobs)
    {
        $data = $request->validate(['public' => ['sometimes', 'required', 'boolean']]);
        // Old parameterless requests can hide, but can never publish.
        $public = (bool) ($data['public'] ?? false);
        $uid = $request->user()->uid;

        return DB::transaction(function () use ($texture, $events, $filter, $blobs, $public, $uid) {
            if (TextureBlobs::validHash($texture->hash)) {
                $blobs->lock($texture->hash);
            }
            $fresh = Texture::lockForUpdate()->findOrFail($texture->tid);
            abort_if($public && $fresh->uploader !== $uid, 403);
            if ($fresh->public === $public) {
                return $this->privacyResponse($public);
            }
            $can = $filter->apply('can_update_texture_privacy', true, [$fresh]);
            if ($can instanceof Rejection) {
                return json($can->getReason(), 1);
            }
            $uploader = User::lockForUpdate()->findOrFail($fresh->uploader);
            $scoreDiff = $fresh->size * ((int) option('private_score_per_storage') - (int) option('score_per_storage'))
                * ($fresh->public ? -1 : 1);
            if ($fresh->public && option('take_back_scores_after_deletion', true)) {
                $scoreDiff -= (int) option('score_award_per_texture', 0);
            }
            if ($uploader->score + $scoreDiff < 0) {
                return json(trans('skinlib.upload.lack-score'), 1);
            }
            if (!$fresh->public) {
                abort_unless($uploader->permission !== User::BANNED && $blobs->has($fresh), 404);
                $duplicate = Texture::published()->where('hash', $fresh->hash)->first();
                if ($duplicate) {
                    return json(trans('skinlib.upload.repeated'), 2, ['tid' => $duplicate->tid]);
                }
            }
            $events->dispatch('texture.privacy.updating', [$fresh]);
            if ($public) {
                // Legacy installations could retain player/avatar slots on a private record.
                // Uploaders may publish the bytes, but clients must explicitly reassign slots.
                $this->references->detachSlots($fresh);
            }
            $uploader->score += $scoreDiff;
            if (!$uploader->save()) {
                throw new \RuntimeException('Texture visibility score update was cancelled.');
            }
            $fresh->public = $public;
            if (!$fresh->save()) {
                throw new \RuntimeException('Texture visibility update was cancelled.');
            }
            $events->dispatch('texture.privacy.updated', [$fresh]);

            return $this->privacyResponse($public);
        });
    }

    private function privacyResponse(bool $public)
    {
        return json(trans('skinlib.privacy.success', [
            'privacy' => trans($public ? 'general.public' : 'general.private'),
        ]), 0);
    }

    public function rename(Request $request, Dispatcher $events, Filter $filter, Texture $texture)
    {
        $name = $request->validate(['name' => $this->nameRules()])['name'];
        $can = $filter->apply('can_update_texture_name', true, [$texture, $name]);
        if ($can instanceof Rejection) {
            return json($can->getReason(), 1);
        }
        $events->dispatch('texture.name.updating', [$texture, $name]);
        $old = $texture->replicate();
        $texture->name = $name;
        $texture->save();
        $events->dispatch('texture.name.updated', [$texture, $old]);

        return json(trans('skinlib.rename.success', ['name' => $name]), 0);
    }

    public function type(Request $request, Dispatcher $events, Filter $filter, Texture $texture, TextureImages $images, TextureBlobs $blobs)
    {
        $type = $request->validate(['type' => ['required', Rule::in(['steve', 'alex', 'cape'])]])['type'];

        return DB::transaction(function () use ($events, $filter, $texture, $type, $images, $blobs) {
            $fresh = Texture::lockForUpdate()->findOrFail($texture->tid);
            $can = $filter->apply('can_update_texture_type', true, [$fresh, $type]);
            if ($can instanceof Rejection) {
                return json($can->getReason(), 1);
            }
            $source = $blobs->read($fresh->hash);
            abort_unless($source, 404);
            if (!$images->matches($source, $type)) {
                return $this->invalidSize($type, $source->width, $source->height);
            }
            $events->dispatch('texture.type.updating', [$fresh, $type]);
            $old = $fresh->replicate();
            $fresh->type = $type;
            $fresh->save();
            $this->references->detachSlots($fresh, onlyIncompatible: true);
            $events->dispatch('texture.type.updated', [$fresh, $old]);

            return json(trans('skinlib.model.success', ['model' => $type]), 0);
        });
    }

    private function nameRules(): array
    {
        $regexp = option('texture_name_regexp');

        return ['required', 'string', 'max:50', ...($regexp ? ['regex:'.$regexp] : [])];
    }

    private function invalidSize(string $type, int $width, int $height)
    {
        return json(trans('skinlib.upload.invalid-size', [
            'type' => $type === 'cape' ? trans('general.cape') : trans('general.skin'),
            'width' => $width,
            'height' => $height,
        ]), 1);
    }
}
