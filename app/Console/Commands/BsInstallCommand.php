<?php

namespace App\Console\Commands;

use App\Services\Installation\Lifecycle;
use App\Services\Installation\LifecycleException;
use Illuminate\Console\Command;
use Throwable;

final class BsInstallCommand extends Command
{
    protected $signature = 'bs:install {email} {nickname} {--password-stdin : Read one password line from standard input}';

    protected $description = 'Initialize an empty instance and create its first administrator offline';

    public function handle(Lifecycle $lifecycle): int
    {
        try {
            $password = $this->readPassword();
            $lifecycle->install($this->argument('email'), $this->argument('nickname'), $password);
        } catch (LifecycleException $failure) {
            $this->error($failure->getMessage());

            return self::FAILURE;
        } catch (Throwable) {
            // SQL and exception diagnostics can include sensitive values even with APP_DEBUG=false.
            $this->error('Installation failed. Check the offline deployment prerequisites and restore a consistent backup if necessary.');

            return self::FAILURE;
        } finally {
            unset($password);
        }

        $this->info('Installation completed. Verify readiness before enabling traffic.');

        return self::SUCCESS;
    }

    private function readPassword(): string
    {
        if ($this->option('password-stdin')) {
            $line = fgets(STDIN, 4099);
            if ($line === false || strlen($line) > 4097) {
                throw new LifecycleException('Read one password line (8–1024 characters) from protected standard input.');
            }

            return rtrim($line, "\r\n");
        }
        if (!$this->input->isInteractive()) {
            throw new LifecycleException('Use --password-stdin for a non-interactive installation.');
        }
        // Disallow falling back to a visible prompt if the terminal cannot conceal input.
        $password = $this->secret('Administrator password', false);
        $confirmation = $this->secret('Confirm administrator password', false);
        if (!is_string($password) || !is_string($confirmation) || !hash_equals($password, $confirmation)) {
            throw new LifecycleException('Administrator password confirmation did not match.');
        }

        return $password;
    }
}
