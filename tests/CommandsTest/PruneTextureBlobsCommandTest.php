<?php

namespace Tests;

use App\Models\Player;
use App\Models\Texture;
use App\Models\User;
use App\Services\Textures\TextureBlobs;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Tests\Support\TextureFixtures;

class PruneTextureBlobsCommandTest extends TestCase
{
    public function testReadOnlyPreflightCountsSourcesAndLegacyReferences(): void
    {
        $disk = Storage::fake('textures');
        $owner = User::factory()->create();
        $skin = Texture::factory()->create(['uploader' => $owner->uid, 'hash' => TextureFixtures::put()]);
        $cape = Texture::factory()->cape()->private()->create([
            'uploader' => $owner->uid, 'hash' => TextureFixtures::put('webp', 64, 32),
        ]);
        $badLayout = Texture::factory()->cape()->create(['hash' => $skin->hash]);
        $missing = Texture::factory()->create();
        $owner->closet()->attach([$skin->tid, $cape->tid]);
        Player::factory()->create(['uid' => $owner->uid, 'tid_skin' => $skin->tid, 'tid_cape' => $cape->tid]);
        Player::factory()->create(['tid_skin' => $missing->tid, 'tid_cape' => $badLayout->tid]);
        $owner->update(['avatar' => $cape->tid]);

        $this->artisan('textures:prune', ['--preflight' => true])
            ->expectsOutput('texture_records: 4')
            ->expectsOutput('private_records: 1')
            ->expectsOutput('supported_png: 1')
            ->expectsOutput('supported_webp: 1')
            ->expectsOutput('missing_or_unreadable: 1')
            ->expectsOutput('incompatible_layout: 1')
            ->expectsOutput('unavailable_skin_references: 1')
            ->expectsOutput('unavailable_cape_references: 2')
            ->expectsOutput('unavailable_avatar_references: 1')
            ->doesntExpectOutput($skin->hash)
            ->assertSuccessful();
        $this->assertDatabaseCount('texture_blobs', 0);
        $this->assertDatabaseCount('textures', 4);
        $this->assertSame($cape->tid, $owner->fresh()->avatar);
        $disk->assertExists([$skin->hash, $cape->hash]);
    }

    public function testDryRunAndCommitRetainReferencedBlobsAndReclaimOnlyOldOrphans(): void
    {
        $disk = Storage::fake('textures');
        $live = TextureFixtures::put();
        $orphan = TextureFixtures::put('webp');
        $recent = TextureFixtures::put('png', 128, 128);
        $blobs = $this->app->make(TextureBlobs::class);
        foreach ([$live, $orphan, $recent] as $hash) {
            $blobs->reserve($hash);
        }
        DB::table('texture_blobs')->whereIn('hash', [$live, $orphan])->update(['unreferenced_at' => now()->subDays(8)]);
        Texture::factory()->create(['hash' => $live]);

        $this->artisan('textures:prune')->expectsOutput('1 registered blobs eligible for reclamation (dry run).')->assertSuccessful();
        $disk->assertExists([$orphan, $live, $recent]);
        $this->artisan('textures:prune', ['--commit' => true])->expectsOutput('Reclaimed 1 registered blobs.')->assertSuccessful();
        $disk->assertMissing($orphan);
        $disk->assertExists([$live, $recent]);
        $this->assertDatabaseMissing('texture_blobs', ['hash' => $orphan]);
        $this->assertDatabaseHas('texture_blobs', ['hash' => $live, 'unreferenced_at' => null]);
    }
}
