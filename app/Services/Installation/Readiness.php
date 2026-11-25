<?php

namespace App\Services\Installation;

use Illuminate\Support\Facades\DB;
use Throwable;

final class Readiness
{
    public function __construct(private KeyMaterial $keys, private MigrationPlan $plan, private RuntimeRequirements $runtime)
    {
    }

    /** No database writes, cache population, secret generation or migrations. */
    public function isReady(): bool
    {
        try {
            $db = DB::connection();
            $this->runtime->verify($db->getDriverName());
            $state = StateStore::read($db);
            $version = config('app.version');

            return $state !== null && $state->status === StateStore::INSTALLED
                && $state->version === $version && $state->target_version === $version
                && hash_equals($state->keys_digest, $this->keys->digest())
                && $this->plan->isComplete($db)
                && $this->plan->hasRequiredSchema($db)
                && $this->plan->reportedVersion($db) === $version;
        } catch (Throwable) {
            // Database down, empty, wrong target or incomplete: do not expose setup or secrets.
            return false;
        }
    }
}
