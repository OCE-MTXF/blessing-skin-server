<?php

namespace Tests;

use App\Services\PluginManager;
use App\Services\Translations\JavaScript;
use Tests\Support\PluginFixture;

class JavaScriptTest extends TestCase
{
    public function testInertSnapshotIncludesOnlyActivePluginMessages(): void
    {
        $fixture = new PluginFixture($this->beforeApplicationDestroyed(...));
        $fixture->package('demo');
        mkdir($fixture->directory.'/demo/lang/en', 0700, true);
        file_put_contents($fixture->directory.'/demo/lang/en/front-end.yml', "welcome: '[Local text] & friends'\n");
        $approved = [
            'name' => 'demo',
            'version' => '1.2.3',
            'sha256' => \App\Services\PluginPackage::inspect($fixture->directory.DIRECTORY_SEPARATOR.'demo')['sha256'],
        ];
        $fixture->approve($approved);
        $manager = $fixture->manager();
        $this->app->instance(PluginManager::class, $manager);
        $translations = new JavaScript($manager);
        $this->assertSame(trans('front-end', [], 'en'), $translations->generate('en'));

        $fixture->select($approved);
        $manager->boot();
        $this->assertSame('[Local text] & friends', $translations->generate('en')['demo']['welcome']);
        $this->assertFileDoesNotExist(public_path('lang/en.js'));
    }

    public function testFallbackLocaleUsesInertData(): void
    {
        $this->get('/', ['Accept-Language' => 'xyz'])->assertOk()
            ->assertSee('<script type="application/json" id="blessing-i18n">', false)
            ->assertDontSee('/lang/en.js');
    }
}
