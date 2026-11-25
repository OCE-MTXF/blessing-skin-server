<?php

namespace App\Http\Controllers;

use App\Models\Player;
use App\Models\Texture;
use App\Models\User;
use App\Services\Textures\InvalidTextureImage;
use App\Services\Textures\TextureBlobs;
use App\Services\Textures\TextureImage;
use App\Services\Textures\TextureImages;
use App\Services\Textures\TextureReferences;
use App\Services\Textures\TextureRenderer;
use App\Services\Textures\TextureRenditions;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class TextureController extends Controller
{
    public function __construct(
        private TextureBlobs $blobs,
        private TextureImages $images,
        private TextureRenderer $renderer,
        private TextureRenditions $renditions,
        private TextureReferences $references,
    ) {
    }

    public function json(Request $request, string $player)
    {
        $player = Player::with('user')->where('name', $player)->firstOrFail();
        abort_if(!$player->user || $player->user->permission === User::BANNED, 403, trans('general.player-banned'));

        // Validate the *current* profile on every request. An ETag, unlike the
        // player's timestamp alone, also reflects a revoked/missing asset.
        return $this->publicResponse($request, response()->json($player->publicProfile($this->references)))
            ->setLastModified($player->last_modified);
    }

    public function previewByHash(Request $request, string $hash): Response
    {
        [$texture, $source] = $this->publishedByHash($hash);

        return $this->renderPreview($request, $texture, true, true, $source);
    }

    public function preview(Request $request, Texture $texture): Response
    {
        $this->authorizeTexture($request, $texture);

        return $this->renderPreview($request, $texture, $this->references->isPublished($texture), false);
    }

    private function renderPreview(Request $request, Texture $texture, bool $published, bool $stateless, ?TextureImage $source = null): Response
    {
        $source ??= $this->source($texture);
        abort_unless($source, 404);
        $height = $texture->type === 'cape' ? $this->size($request, 'height', 200, TextureImages::CAPE_HEIGHTS) : 0;
        $format = $this->images->outputFormat($request->has('png'));
        $bytes = $this->render("preview:{$texture->type}:$height:$format", $source,
            $published && option('enable_preview_cache'),
            fn () => $this->images->encode($this->renderer->preview($source, $texture->type, $height), $format));

        return $this->imageResponse($request, $bytes, $format, $stateless);
    }

    public function raw(Request $request, int $tid): Response
    {
        abort_unless(option('allow_downloading_texture'), 403);

        return $this->asset($request, Texture::findOrFail($tid));
    }

    public function asset(Request $request, Texture $texture): Response
    {
        $this->authorizeTexture($request, $texture);
        $source = $this->source($texture);
        abort_unless($source, 404);

        return $this->imageResponse($request, $source->decodeBytes, $source->format, false);
    }

    public function texture(Request $request, string $hash): Response
    {
        [, $source] = $this->publishedByHash($hash);

        return $this->imageResponse($request, $source->decodeBytes, $source->format, true);
    }

    public function avatarByPlayer(Request $request, string $name): Response
    {
        $player = Player::where('name', $name)->firstOrFail();
        $texture = $this->references->gameTexture($player->user, $player->skin, 'skin');

        return $this->avatar($request, $texture, true, true);
    }

    public function avatarByUser(Request $request, int $uid): Response
    {
        $user = User::find($uid);
        $texture = $user && $user->avatar ? $this->references->gameTexture($user, Texture::find($user->avatar), 'avatar') : null;

        return $this->avatar($request, $texture, true, true);
    }

    public function avatarByHash(Request $request, string $hash): Response
    {
        [$texture, $source] = $this->publishedByHash($hash, true);

        return $this->avatar($request, $texture, true, true, $source);
    }

    public function avatarByTexture(Request $request, int $tid): Response
    {
        $texture = Texture::find($tid);
        if ($texture) {
            $this->authorizeTexture($request, $texture);
        }

        return $this->avatar($request, $texture, $texture === null || $this->references->isPublished($texture), false);
    }

    private function avatar(Request $request, ?Texture $texture, bool $published, bool $stateless, ?TextureImage $source = null): Response
    {
        abort_if($texture && !$texture->fitsSlot('avatar'), 422);
        $source ??= $texture ? $this->source($texture) : null;
        $size = $this->size($request, 'size', 100, TextureImages::AVATAR_SIZES);
        $mode = $request->has('3d') ? '3d' : '2d';
        $format = $this->images->outputFormat($request->has('png'));
        $bytes = $this->render("avatar:$mode:$size:$format", $source,
            $published && option('enable_avatar_cache'),
            fn () => $this->images->encode($this->renderer->avatar($source, $size, $mode === '3d'), $format));

        return $this->imageResponse($request, $bytes, $format, $stateless);
    }

    private function source(Texture $texture): ?TextureImage
    {
        $source = $this->blobs->read($texture->hash);

        return $source && $this->images->matches($source, $texture->type) ? $source : null;
    }

    /** @return array{Texture, TextureImage} */
    private function publishedByHash(string $hash, bool $skinOnly = false): array
    {
        abort_unless(TextureBlobs::validHash($hash), 404);
        $query = Texture::published()->where('hash', $hash)
            ->whereIn('type', $skinOnly ? ['steve', 'alex'] : ['steve', 'alex', 'cape']);
        abort_unless((clone $query)->exists(), 404);
        $source = $this->blobs->read($hash);
        abort_unless($source, 404);
        $types = array_values(array_filter($skinOnly ? ['steve', 'alex'] : ['steve', 'alex', 'cape'],
            fn (string $type) => $this->images->matches($source, $type)));

        // A surviving compatible public record keeps the digest available,
        // even when an earlier legacy record is mislabeled or private.
        return [$query->whereIn('type', $types)->orderBy('tid')->firstOrFail(), $source];
    }

    private function authorizeTexture(Request $request, Texture $texture): void
    {
        abort_unless($this->references->canView($request->user(), $texture), 404);
    }

    private function render(string $variant, ?TextureImage $source, bool $cache, \Closure $encode): string
    {
        try {
            return $this->renditions->get($variant, $source, $cache, $encode);
        } catch (InvalidTextureImage) {
            abort(404);
        }
    }

    private function size(Request $request, string $name, int $default, array $allowed): int
    {
        $query = $request->query();
        if (!array_key_exists($name, $query)) {
            return $default;
        }
        $value = $query[$name];
        abort_unless(is_scalar($value) && ctype_digit((string) $value) && in_array((int) $value, $allowed, true), 422);

        return (int) $value;
    }

    private function imageResponse(Request $request, string $bytes, string $format, bool $stateless): Response
    {
        $response = response($bytes, 200, [
            'Content-Type' => 'image/'.$format,
            'Content-Length' => (string) strlen($bytes),
            'X-Content-Type-Options' => 'nosniff',
        ]);
        if ($stateless) {
            return $this->publicResponse($request, $response);
        }

        // Optional session scope: neither owner-only nor cookie-bearing
        // representations may be persisted in a shared HTTP cache.
        $response->headers->set('Cache-Control', 'private, no-store');

        return $response;
    }

    private function publicResponse(Request $request, Response $response): Response
    {
        $response->headers->set('Cache-Control', 'public, no-cache, max-age=0, must-revalidate');
        $response->setEtag(hash('sha256', $response->getContent()));
        // Never honor date-only validators when authorization or references
        // can change without updating the source file's timestamp.
        if ($request->headers->has('If-None-Match')) {
            $response->isNotModified($request);
        }

        return $response;
    }
}
