<?php

namespace Tests;

use App\Models\User;
use App\Services\PluginManager;
use Tests\Support\PluginFixture;

class PluginControllerTest extends TestCase
{
    private PluginFixture $fixture;

    protected function setUp(): void
    {
        parent::setUp();
        $this->fixture = new PluginFixture($this->beforeApplicationDestroyed(...));
    }

    public function testBrowsingAndSafeReadmeRemainAvailableWithoutActivatingAPlugin(): void
    {
        $record = $this->fixture->package('demo');
        $this->fixture->approve($record);
        $this->app->instance(PluginManager::class, $this->fixture->manager());
        $this->actingAs(User::factory()->create(['permission' => User::ADMIN])->refresh());

        $this->getJson('/admin/plugins/data')->assertOk()->assertJsonPath('0.name', 'demo')
            ->assertJsonPath('0.approved', true)->assertJsonPath('0.enabled', false)
            ->assertJsonPath('0.readme', true)->assertJsonPath('0.can_manage', false)
            ->assertJsonPath('0.config', false);
        $this->get('/admin/plugins/readme/demo')->assertOk()->assertSee('<h1>Reviewed documentation</h1>', false);
        $this->get('/admin/plugins/config/demo')->assertForbidden();
        $this->postJson('/admin/plugins/manage', ['name' => 'demo', 'action' => 'enable'])->assertForbidden();
        $this->assertFalse(app()->bound('tests.plugin.loaded.demo'));
    }

    public function testSuperAdminMaySelectOnlyReviewedPackagesAndWebDeleteIsUnavailable(): void
    {
        $approved = $this->fixture->package('approved');
        $this->fixture->package('unapproved');
        $this->fixture->approve($approved);
        $this->app->instance(PluginManager::class, $this->fixture->manager());
        $this->actingAs(User::factory()->create(['permission' => User::SUPER_ADMIN])->refresh());

        $this->get('/admin/plugins/config/approved')->assertNotFound();
        $this->get('/admin/plugins/readme/unapproved')->assertNotFound();
        $this->postJson('/admin/plugins/manage', ['name' => 'approved', 'action' => 'delete'])->assertUnprocessable();
        $this->postJson('/admin/plugins/manage', ['name' => 'unapproved', 'action' => 'enable'])->assertJsonPath('code', 1);
        $this->postJson('/admin/plugins/manage', ['name' => 'approved', 'action' => 'enable'])->assertJsonPath('code', 0);
        $this->assertSame([$approved], json_decode(option('plugins_enabled'), true));
        $this->assertFalse(app()->bound('tests.plugin.loaded.approved'));
    }

    public function testOnlyVerifiedActiveAssetsAndConfigAreServed(): void
    {
        $approved = $this->fixture->package('demo');
        $this->fixture->approve($approved);
        $this->fixture->select($approved);
        $manager = $this->fixture->manager();
        $this->app->instance(PluginManager::class, $manager);
        $manager->boot();

        $prefix = '/plugin-assets/demo/'.$approved['sha256'];
        $this->assertSame($prefix.'/styles/demo.css', $manager->get('demo')->assets('styles/demo.css'));
        $this->get($prefix.'/styles/demo.css')->assertOk()->assertHeader('Content-Type', 'text/css; charset=UTF-8')
            ->assertHeader('X-Content-Type-Options', 'nosniff')->assertSee('.plugin-demo { color: blue; }', false);
        $this->get($prefix.'/note.html')->assertNotFound();
        $this->get('/plugin-assets/demo/'.str_repeat('0', 64).'/styles/demo.css')->assertNotFound();
        file_put_contents($this->fixture->directory.'/demo/assets/styles/demo.css', '.plugin-demo { color: green; }');
        $this->get($prefix.'/styles/demo.css')->assertNotFound();

        $this->actingAs(User::factory()->create(['permission' => User::SUPER_ADMIN])->refresh());
        $this->get('/admin/plugins/config/demo')->assertOk()->assertSee('Plugin configuration.');
    }

    public function testRetiredOnlinePluginRoutesAreNotRegistered(): void
    {
        $routes = app('router')->getRoutes();
        foreach (['admin.plugins.upload', 'admin.plugins.wget', 'admin.plugins.market.download'] as $name) {
            $this->assertNull($routes->getByName($name));
        }
    }
}
