<?php

namespace Tests;

use App\Events;
use App\Models\User;
use App\Services\PluginManager;
use Illuminate\Support\Facades\Event;
use Symfony\Component\DomCrawler\Crawler;
use Tests\Support\PluginFixture;

class SideMenuComposerTest extends TestCase
{
    public function testEvents()
    {
        Event::fake();

        $admin = User::factory()->admin()->create();
        $this->actingAs($admin)->get('/user');
        Event::assertDispatched(Events\ConfigureUserMenu::class);
        Event::assertDispatched(Events\ConfigureExploreMenu::class);

        $this->get('/admin');
        Event::assertDispatched(Events\ConfigureAdminMenu::class);
    }

    public function testFilter()
    {
        $filter = Fakes\Filter::fake();

        $user = User::factory()->create();
        $this->actingAs($user)->get('/user');
        $filter->assertHaveBeenApplied('side_menu', function ($menu, $type) {
            $this->assertCount(count(config('menu.user')), $menu);
            $this->assertEquals('user', $type);

            return true;
        });
        $filter->assertApplied('side_menu', function ($menu, $type) {
            $this->assertCount(count(config('menu.explore')), $menu);
            $this->assertEquals('explore', $type);

            return true;
        });
    }

    public function testTransform()
    {
        $user = User::factory()->create();
        $this->actingAs($user);

        $crawler = new Crawler($this->get('/user/oauth/manage')->getContent());
        $this->assertCount(1, $crawler->filter('aside .nav-treeview'));
        $this->assertCount(2, $crawler->filter('aside .nav-item.active'));
        $this->assertCount(2, $crawler->filter('aside .nav-link.active'));
    }

    public function testPluginConfigMenuRequiresApprovalActivationAndSuperAdmin(): void
    {
        $fixture = new PluginFixture($this->beforeApplicationDestroyed(...));
        $record = $fixture->package('demo');
        $fixture->approve($record);
        $manager = $fixture->manager();
        $this->app->instance(PluginManager::class, $manager);

        $superAdmin = User::factory()->superAdmin()->create()->refresh();
        $this->actingAs($superAdmin)->get('/admin/options')->assertOk()
            ->assertDontSee('admin/plugins/config/demo')->assertSee('admin/i18n');

        $fixture->select($record);
        $manager->boot();
        $this->actingAs(User::factory()->admin()->create()->refresh())->get('/admin/options')->assertOk()
            ->assertDontSee('admin/plugins/config/demo')->assertDontSee('admin/i18n');
        $this->actingAs($superAdmin)->get('/admin/options')->assertOk()
            ->assertSee(trans('general.plugin-configs'))
            ->assertSee('Demo demo')->assertSee('admin/plugins/config/demo');
    }
}
