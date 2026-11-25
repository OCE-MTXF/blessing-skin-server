<?php

namespace App\Services\Textures;

use App\Models\Texture;
use Illuminate\Filesystem\FilesystemAdapter;
use Illuminate\Filesystem\LocalFilesystemAdapter;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use League\Flysystem\FilesystemException;

final class TextureBlobs
{
    public function __construct(private TextureImages $images, private TextureWorkBudget $budget) {}

    public static function validHash(string $hash): bool
    {
        return preg_match('/\A[a-f0-9]{64}\z/', $hash) === 1;
    }

    public function read(string $hash): ?TextureImage
    {
        return $this->budget->run(fn () => $this->probe($hash)[1]);
    }

    /** @return array{0: string, 1: ?TextureImage} */
    public function probe(string $hash): array
    {
        if (!self::validHash($hash)) {
            return ['invalid_digest', null];
        }
        try {
            $stream = $this->disk()->readStream($hash);
        } catch (FilesystemException) {
            return ['missing_or_unreadable', null];
        }
        if (!is_resource($stream)) {
            return ['missing_or_unreadable', null];
        }
        try {
            $source = $this->images->read($stream);
            if (!hash_equals($hash, hash('sha256', $source->bytes))) {
                return ['digest_mismatch', null];
            }
            // Header checks constrain resource use; a full decode also rules out
            // incomplete sources for raw delivery and for new references.
            $this->images->decode($source);

            return ['ok', $source];
        } catch (InvalidTextureImage) {
            return ['invalid_image', null];
        }
    }

    public function has(Texture $texture): bool
    {
        $source = $this->read($texture->hash);

        return $source !== null && $this->images->matches($source, $texture->type);
    }

    /** Keep this reservation even if a later database transaction rolls back publication. */
    public function reserve(string $hash): void
    {
        if (!self::validHash($hash)) {
            throw new \InvalidArgumentException('Invalid texture digest.');
        }
        DB::table('texture_blobs')->insertOrIgnore(['hash' => $hash, 'unreferenced_at' => now()]);
    }

    /** Acquire this lock first, before a texture/user row, inside a transaction. */
    public function lock(string $hash): void
    {
        if (DB::transactionLevel() === 0) {
            throw new \LogicException('Texture digest updates require a transaction.');
        }
        $this->reserve($hash);
        DB::table('texture_blobs')->where('hash', $hash)->lockForUpdate()->first();
    }

    /** A write is not published until complete, verified, and renamed on the same filesystem. */
    public function publish(string $hash, string $bytes): void
    {
        if (!hash_equals($hash, hash('sha256', $bytes)) || DB::transactionLevel() === 0) {
            throw new \LogicException('Cannot publish a texture outside its digest transaction.');
        }
        $disk = $this->disk();
        if (!$disk instanceof LocalFilesystemAdapter) {
            throw new \RuntimeException('Texture upload needs a local disk with atomic rename.');
        }
        if ($disk->exists($hash)) {
            $existing = $this->read($hash);
            if (!$existing || !hash_equals($hash, hash('sha256', $existing->bytes))) {
                throw new \RuntimeException('Stored texture does not match its digest.');
            }

            return;
        }
        $staging = '.staging/'.bin2hex(random_bytes(16));
        try {
            if (!$disk->put($staging, $bytes, 'private')
                || !hash_equals($hash, (string) @hash_file('sha256', $disk->path($staging)))
                || !@rename($disk->path($staging), $disk->path($hash))) {
                throw new \RuntimeException('Texture storage did not complete the write.');
            }
        } finally {
            if ($disk->exists($staging)) {
                $disk->delete($staging);
            }
        }
    }

    public function reconcile(string $hash): void
    {
        if (!self::validHash($hash)) {
            return;
        }
        DB::table('texture_blobs')->where('hash', $hash)->update([
            'unreferenced_at' => Texture::where('hash', $hash)->exists() ? null : now(),
        ]);
    }

    public function prune(int $limit, bool $delete): int
    {
        $cutoff = now()->subDays(7);
        $candidates = DB::table('texture_blobs')->where('unreferenced_at', '<', $cutoff)
            ->orderBy('unreferenced_at')->limit($limit)->pluck('hash');
        $count = 0;
        foreach ($candidates as $hash) {
            if (!self::validHash($hash)) {
                continue;
            }
            $count += (int) DB::transaction(function () use ($hash, $cutoff, $delete) {
                $row = DB::table('texture_blobs')->where('hash', $hash)->where('unreferenced_at', '<', $cutoff)
                    ->lockForUpdate()->first();
                if (!$row || Texture::where('hash', $hash)->exists()) {
                    if ($row && $delete) {
                        $this->reconcile($hash);
                    }

                    return false;
                }
                if ($delete) {
                    if (!$this->disk()->delete($hash)) {
                        throw new \RuntimeException('Texture storage declined to delete an unreferenced object.');
                    }
                    DB::table('texture_blobs')->where('hash', $hash)->delete();
                }

                return true;
            });
        }

        return $count;
    }

    private function disk(): FilesystemAdapter
    {
        return Storage::disk('textures');
    }
}
