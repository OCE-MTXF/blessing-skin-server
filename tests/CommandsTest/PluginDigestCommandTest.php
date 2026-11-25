<?php

namespace Tests;

use Tests\Support\PluginFixture;

class PluginDigestCommandTest extends TestCase
{
    public function testDigestUsesExactPackageBytesWithoutActivation(): void
    {
        $fixture = new PluginFixture($this->beforeApplicationDestroyed(...));
        $record = $fixture->package('demo');
        $path = $fixture->directory.DIRECTORY_SEPARATOR.'demo';
        $fixture->approve($record);
        $fixture->select($record);
        $this->artisan('plugin:digest', ['directory' => $path])
            ->expectsOutput(json_encode($record, JSON_THROW_ON_ERROR))
            ->assertSuccessful();
        $this->assertFalse($this->app->bound('tests.plugin.loaded.demo'));
        $this->assertSame([$record], json_decode(option('plugins_enabled'), true));
    }

    public function testDigestRefusesAnInvalidPackageDirectory(): void
    {
        $this->artisan('plugin:digest', ['directory' => '../demo'])->assertExitCode(1);
    }
}
