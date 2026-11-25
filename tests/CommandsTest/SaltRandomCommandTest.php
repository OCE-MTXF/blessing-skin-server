<?php

namespace Tests\CommandsTest;

use Illuminate\Contracts\Console\Kernel;
use Symfony\Component\Console\Exception\CommandNotFoundException;
use Tests\TestCase;

final class SaltRandomCommandTest extends TestCase
{
    public function testRetiredSaltCommandLeavesDeploymentConfigurationUntouched(): void
    {
        $path = storage_path('.env');
        $this->app->useEnvironmentPath(storage_path());
        $contents = "SALT=synthetic-legacy-value\n";
        file_put_contents($path, $contents);
        $kernel = $this->app->make(Kernel::class);

        $this->assertArrayNotHasKey('salt:random', $kernel->all());
        try {
            $kernel->call('salt:random');
            $this->fail('The legacy salt mutation command should be unavailable.');
        } catch (CommandNotFoundException) {
            $this->assertSame($contents, file_get_contents($path));
        }
    }
}
