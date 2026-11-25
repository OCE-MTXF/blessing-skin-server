<?php

namespace Tests;

use App\Services\PluginManager;

class PluginDisableCommandTest extends TestCase
{
    public function testDisableReportsSelectionResults(): void
    {
        $this->mock(PluginManager::class, function ($mock) {
            $mock->shouldReceive('disable')->with('missing')->once()->andReturn(false);
            $mock->shouldReceive('disable')->with('demo')->once()->andReturn(true);
        });

        $this->artisan('plugin:disable missing')->assertExitCode(1);
        $this->artisan('plugin:disable demo')
            ->expectsOutput(trans('admin.plugins.operations.disabled', ['plugin' => 'demo']))
            ->assertSuccessful();
    }
}
