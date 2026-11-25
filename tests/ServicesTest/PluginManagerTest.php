<?php

namespace Tests;

use App\Services\Installation\StateStore;
use App\Services\PluginPackage;
use Illuminate\Routing\RouteCollection;
use Illuminate\Support\Facades\DB;
use Tests\Support\PluginFixture;

class PluginManagerTest extends TestCase
{
    private PluginFixture $fixture;

    protected function setUp(): void
    {
        parent::setUp();
        $this->fixture = new PluginFixture($this->beforeApplicationDestroyed(...));
    }

    public function testOnlyExplicitlySelectedExactApprovalsBoot(): void
    {
        $selected = $this->fixture->package('selected');
        $off = $this->fixture->package('off');
        $unknown = $this->fixture->package('unknown');
        $this->fixture->approve($selected, $off);
        $this->fixture->select($selected, $unknown);

        $manager = $this->fixture->manager();
        $manager->boot();
        $this->assertSame(['selected'], $manager->getEnabledPlugins()->keys()->all());
        $this->assertTrue(app()->make('tests.plugin.loaded.selected'));
        $this->assertFalse(app()->bound('tests.plugin.loaded.off'));
        $this->assertFalse(app()->bound('tests.plugin.loaded.unknown'));
        $this->assertTrue($manager->get('off')->isApproved());
        $this->assertFalse($manager->get('off')->isEnabled());
        $this->assertFalse($manager->get('unknown')->isApproved());
    }

    public function testApprovedBootstrapMayResolveOwnAssetBeforeRoutesAreRegistered(): void
    {
        $record = $this->fixture->package('reviewed');
        $this->fixture->approve($record);
        $this->fixture->select($record);
        $router = $this->app['router'];
        $url = $this->app['url'];
        $routes = $router->getRoutes();
        $empty = new RouteCollection();
        try {
            $router->setRoutes($empty);
            $url->setRoutes($empty);
            $manager = $this->fixture->manager();
            $manager->boot();

            $this->assertTrue($manager->get('reviewed')->isEnabled());
            $this->assertSame('/plugin-assets/reviewed/'.$record['sha256'].'/styles/demo.css',
                app()->make('tests.plugin.asset.reviewed'));
        } finally {
            $router->setRoutes($routes);
            $url->setRoutes($routes);
        }
    }

    public function testLegacyRowsAndDifferentDigestDoNotEnableAReplacement(): void
    {
        $record = $this->fixture->package('demo');
        $this->fixture->approve($record);
        option(['plugins_enabled' => json_encode(['demo' => '1.2.3'])]);
        $manager = $this->fixture->manager();
        $manager->boot();
        $this->assertEmpty($manager->getEnabledPlugins());

        // Deploy approves changed bytes with the same name and version, but the old
        // activation record is intentionally not carried over to the new digest.
        $this->fixture->select($record);
        $path = $this->fixture->directory.'/demo';
        file_put_contents($path.'/README.md', 'Revised documentation.');
        $manager = $this->fixture->manager();
        $manager->boot();
        $this->assertFalse($manager->get('demo')->isApproved());

        $replacement = array_replace($record, ['sha256' => PluginPackage::inspect($path)['sha256']]);
        $this->fixture->approve($replacement);
        $manager = $this->fixture->manager();
        $manager->boot();
        $this->assertTrue($manager->get('demo')->isApproved());
        $this->assertFalse($manager->get('demo')->isEnabled());
        $this->assertFalse(app()->bound('tests.plugin.loaded.demo'));
    }

    public function testMissingPinWritableLocationsAndAmbiguousNameNeverBoot(): void
    {
        $record = $this->fixture->package('demo');
        $this->fixture->approve($record);
        $this->fixture->select($record);
        $manager = $this->fixture->manager(false);
        $manager->boot();
        $this->assertEmpty($manager->getEnabledPlugins());
        $this->assertFalse(app()->bound('tests.plugin.loaded.demo'));

        $pin = config('plugins.approvals_sha256');
        config(['plugins.approvals_sha256' => str_repeat('0', 64)]);
        $manager = $this->fixture->manager();
        $manager->boot();
        $this->assertFalse($manager->get('demo')->isApproved());
        $this->assertFalse($manager->enable('demo'));

        config(['plugins.approvals_sha256' => $pin]);
        $this->fixture->package('demo', [], 'second-copy');
        $manager = $this->fixture->manager();
        $manager->boot();
        $this->assertFalse($manager->get('demo')->isApproved());
        $this->assertFalse(app()->bound('tests.plugin.loaded.demo'));
    }

    public function testUnableToActivateBeforeInstallationIsReady(): void
    {
        $record = $this->fixture->package('demo');
        $this->fixture->approve($record);
        $this->fixture->select($record);
        DB::table(StateStore::TABLE)->where('id', 1)->update(['status' => StateStore::UPGRADING]);
        $manager = $this->fixture->manager();
        $manager->boot();
        $this->assertEmpty($manager->getEnabledPlugins());
        $this->assertFalse($manager->enable('demo'));
        $this->assertFalse($manager->disable('demo'));
    }

    public function testMutationsOnlyPersistDigestBoundFlagsAndDisableDependants(): void
    {
        $base = $this->fixture->package('base');
        $dependent = $this->fixture->package('dependent', ['require' => ['base' => '^1.0']]);
        $this->fixture->approve($base, $dependent);
        $manager = $this->fixture->manager();
        $this->assertArrayHasKey('base', $manager->enable('dependent')['unsatisfied']->all());
        $this->assertTrue($manager->enable('base'));
        $this->assertTrue($manager->enable('dependent'));
        $this->assertSame([$base, $dependent], json_decode(option('plugins_enabled'), true));
        $this->assertFalse($manager->get('base')->isEnabled());
        $this->assertFalse(app()->bound('tests.plugin.loaded.base'));

        $manager = $this->fixture->manager();
        $manager->boot();
        $this->assertSame(['base', 'dependent'], $manager->getEnabledPlugins()->keys()->all());
        $this->assertTrue($manager->disable('base'));
        $this->assertSame([], json_decode(option('plugins_enabled'), true));
        $next = $this->fixture->manager();
        $next->boot();
        $this->assertEmpty($next->getEnabledPlugins());
    }

    public function testMissingPrerequisitesCyclesAndConflictsFailClosed(): void
    {
        $missing = $this->fixture->package('missing', ['require' => ['not-installed' => '^1.0']]);
        $cycleA = $this->fixture->package('cycle-a', ['require' => ['cycle-b' => '*']]);
        $cycleB = $this->fixture->package('cycle-b', ['require' => ['cycle-a' => '*']]);
        $conflictA = $this->fixture->package('conflict-a', ['enchants' => ['conflicts' => ['conflict-b' => '*']]]);
        $conflictB = $this->fixture->package('conflict-b');
        $dependant = $this->fixture->package('dependant', ['require' => ['conflict-a' => '*']]);
        $records = [$missing, $cycleA, $cycleB, $conflictA, $conflictB, $dependant];
        $this->fixture->approve(...$records);
        $this->fixture->select(...$records);

        $manager = $this->fixture->manager();
        $manager->boot();
        $this->assertEmpty($manager->getEnabledPlugins());
        foreach ($records as $record) {
            $this->assertFalse(app()->bound('tests.plugin.loaded.'.$record['name']));
        }
    }
}
