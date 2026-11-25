<?php

namespace App\Console\Commands;

use App\Services\Installation\Lifecycle;
use App\Services\Installation\LifecycleException;
use Illuminate\Console\Command;
use Throwable;

final class UpdateCommand extends Command
{
    protected $signature = 'bs:upgrade {--adopt-legacy : Explicitly adopt a verified, complete 6.0.2 database}';

    protected $description = 'Upgrade schema and state for a staged release offline (no downloads)';

    public function handle(Lifecycle $lifecycle): int
    {
        try {
            $changed = $lifecycle->upgrade($this->option('adopt-legacy'));
        } catch (LifecycleException $failure) {
            $this->error($failure->getMessage());

            return self::FAILURE;
        } catch (Throwable) {
            $this->error('Upgrade failed. Keep traffic paused and restore a consistent backup if necessary.');

            return self::FAILURE;
        }

        $this->info($changed ? 'Upgrade completed. Verify readiness before enabling traffic.' : 'Already at the current release.');

        return self::SUCCESS;
    }
}
