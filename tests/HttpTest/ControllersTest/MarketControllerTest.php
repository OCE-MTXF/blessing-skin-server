<?php

namespace Tests;

use App\Models\User;
use App\Services\PluginManager;
use Illuminate\Support\Facades\Http;
use Tests\Support\PluginFixture;

class MarketControllerTest extends TestCase
{
    public function testCatalogIsEmptyWithoutLocalDeploymentFile(): void
    {
        Http::fake();
        $this->actingAs(User::factory()->admin()->create()->refresh());
        $this->getJson('/admin/plugins/market/list')->assertOk()->assertExactJson([]);
        Http::assertNothingSent();
    }

    public function testCatalogExposesOnlyDescriptionAndCompatibilityMetadata(): void
    {
        Http::fake();
        $fixture = new PluginFixture($this->beforeApplicationDestroyed(...));
        $fixture->package('demo');
        $catalog = $fixture->root.DIRECTORY_SEPARATOR.'catalog.json';
        file_put_contents($catalog, json_encode(['packages' => [
            [
                'name' => 'demo',
                'version' => '2.0.0',
                'title' => 'Demo project',
                'description' => 'Offline package description',
                'author' => 'Maintainer',
                'dist' => ['label' => 'Not part of the catalog response'],
                'require' => ['demo-base' => '^1.0'],
            ],
            ['version' => '1.0.0'],
        ]], JSON_THROW_ON_ERROR));
        config(['plugins.catalog_file' => $catalog]);
        $this->app->instance(PluginManager::class, $fixture->manager());
        $this->actingAs(User::factory()->admin()->create()->refresh());

        $this->getJson('/admin/plugins/market/list')->assertOk()->assertExactJson([[
            'name' => 'demo',
            'version' => '2.0.0',
            'title' => 'Demo project',
            'description' => 'Offline package description',
            'author' => 'Maintainer',
            'installed' => '1.2.3',
            'can_update' => true,
            'dependencies' => [
                'all' => ['demo-base' => '^1.0'],
                'unsatisfied' => ['demo-base' => ['version' => null, 'constraint' => '^1.0']],
            ],
        ]]);
        Http::assertNothingSent();
    }
}
