<?php

namespace Tests;

use App\Models\Player;
use App\Models\Texture;
use App\Models\User;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\Storage;
use Illuminate\Testing\TestResponse;
use Tests\Support\TextureFixtures;

class TextureControllerTest extends TestCase
{
    use DatabaseTransactions;

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('textures');
        option(['allow_downloading_texture' => true, 'enable_avatar_cache' => true, 'enable_preview_cache' => true]);
    }

    public function testLegacyPngAndWebpPathsServeTheirActualFormats(): void
    {
        foreach (['png' => IMAGETYPE_PNG, 'webp' => IMAGETYPE_WEBP] as $format => $expected) {
            $texture = Texture::factory()->create(['hash' => TextureFixtures::put($format)]);
            foreach (["/textures/$texture->hash", "/csl/textures/$texture->hash", "/raw/$texture->tid", "/asset/$texture->tid"] as $url) {
                $response = $this->get($url)->assertOk()->assertHeader('Content-Type', 'image/'.$format)
                    ->assertHeader('X-Content-Type-Options', 'nosniff');
                $this->assertSame([64, 64, $expected], array_slice(getimagesizefromstring($response->getContent()), 0, 3));
                $this->assertInstanceOf(\GdImage::class, imagecreatefromstring($response->getContent()));
            }
        }

        option(['allow_downloading_texture' => false]);
        $this->get('/raw/'.$texture->tid)->assertForbidden();
        $this->get('/asset/'.$texture->tid)->assertOk();
        $this->get('/csl/textures/'.$texture->hash)->assertOk();
    }

    public function testPublishedProfileAndAvatarRevalidateVisibilityAfterCacheWarmup(): void
    {
        $skin = Texture::factory()->alex()->create(['hash' => TextureFixtures::put('webp')]);
        $cape = Texture::factory()->cape()->create(['hash' => TextureFixtures::put('png', 64, 32)]);
        $player = Player::factory()->create(['tid_skin' => $skin->tid, 'tid_cape' => $cape->tid]);
        $player->user->closet()->attach([$skin->tid, $cape->tid]);
        $player->user->update(['avatar' => $skin->tid]);
        $profile = ['/csl/'.$player->name.'.json', '/'.$player->name.'.json'];
        foreach ($profile as $path) {
            $json = $this->getJson($path)->assertOk()->assertHeader('Last-Modified')->assertExactJson([
                'username' => $player->name, 'skins' => ['slim' => $skin->hash], 'cape' => $cape->hash,
            ]);
            $this->getJson($path, ['If-None-Match' => $json->headers->get('ETag')])->assertStatus(304);
        }
        $etag = $json->headers->get('ETag');
        $default = $this->get('/avatar/0?png&size=50')->assertOk()->getContent();
        $avatar = $this->get('/avatar/player/'.$player->name.'?png&size=50')->assertOk()->getContent();
        $this->assertNotSame($default, $avatar);
        $this->assertSame($avatar, $this->get('/avatar/user/'.$player->uid.'?png&size=50')->assertOk()->getContent());
        $hashAvatar = $this->get('/avatar/hash/'.$skin->hash.'?png&size=50')->assertOk()->headers->get('ETag');
        $this->get('/preview/hash/'.$skin->hash.'?png')->assertOk();

        $skin->update(['public' => false]);
        $this->get('/avatar/hash/'.$skin->hash.'?png&size=50', ['If-None-Match' => $hashAvatar])->assertNotFound();
        $this->get('/preview/hash/'.$skin->hash.'?png')->assertNotFound();
        $this->assertSame($default, $this->get('/avatar/user/'.$player->uid.'?png&size=50')->assertOk()->getContent());
        $this->getJson($profile[1], ['If-None-Match' => $etag])->assertOk()->assertExactJson([
            'username' => $player->name, 'skins' => ['default' => null], 'cape' => $cape->hash,
        ]);
    }

    public function testOwnerAndStaffHavePrivateNoStoreTidViews(): void
    {
        $texture = Texture::factory()->private()->create(['hash' => TextureFixtures::put()]);
        $paths = ['/asset/'.$texture->tid, '/raw/'.$texture->tid, '/preview/'.$texture->tid, '/avatar/'.$texture->tid];
        foreach ($paths as $path) {
            $this->get($path)->assertNotFound();
        }
        foreach ([$texture->owner, User::factory()->admin()->create()] as $actor) {
            $this->actingAs($actor);
            foreach ($paths as $path) {
                $this->assertStringContainsString('no-store', $this->get($path)->assertOk()->headers->get('Cache-Control'));
            }
            $this->get('/textures/'.$texture->hash)->assertNotFound();
        }
    }

    public function testCapeRenderVariantsAndSkinAvatarsHaveBoundedIndependentDimensions(): void
    {
        $cape = Texture::factory()->cape()->create(['hash' => TextureFixtures::put('webp', 64, 32)]);
        $small = $this->get('/preview/hash/'.$cape->hash.'?height=100&png')->assertOk();
        $tall = $this->get('/preview/hash/'.$cape->hash.'?height=200&png')->assertOk();
        $this->assertImage($small, 142, 140, IMAGETYPE_PNG);
        $this->assertImage($tall, 205, 240, IMAGETYPE_PNG);
        $this->assertSame($small->getContent(), $this->get('/preview/hash/'.$cape->hash.'?height=100&png')->assertOk()->getContent());

        $skin = Texture::factory()->create(['hash' => TextureFixtures::put('png', 64, 32)]);
        $this->assertImage($this->get('/preview/'.$skin->tid)->assertOk(), 400, 312, IMAGETYPE_WEBP);
        $a = $this->get('/avatar/hash/'.$skin->hash.'?size=50&png')->assertOk();
        $b = $this->get('/avatar/hash/'.$skin->hash.'?size=50&3d&png')->assertOk();
        $this->assertImage($a, 50, 50, IMAGETYPE_PNG);
        $this->assertImage($b, 50, 50, IMAGETYPE_PNG);
        $this->assertNotSame($a->getContent(), $b->getContent());
    }

    private function assertImage(TestResponse $response, int $width, int $height, int $format): void
    {
        $this->assertSame([$width, $height, $format], array_slice(getimagesizefromstring($response->getContent()), 0, 3));
    }
}
