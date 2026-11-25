<?php

namespace Tests;

use App\Services\PluginManager;

class PluginEnableCommandTest extends TestCase
{
    public function testEnableReportsApprovalAndActivationResults(): void
    {
        $this->mock(PluginManager::class, function ($mock) {
            $mock->shouldReceive('enable')->with('unreviewed')->once()->andReturn(false);
            $mock->shouldReceive('enable')->with('demo')->once()->andReturn(true);
        });

        $this->artisan('plugin:enable unreviewed')->assertExitCode(1);
        $this->artisan('plugin:enable demo')
            ->expectsOutput(trans('admin.plugins.operations.enabled', ['plugin' => 'demo']))
            ->assertSuccessful();
    }
}
