<?php

use App\Services\Installation\StateStore;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        // Lifecycle commands create this before running the normal migration plan.
        StateStore::ensure(DB::connection());
    }

    public function down(): void
    {
        // Only used by disposable databases. Restore a consistent backup in production.
        Schema::dropIfExists(StateStore::TABLE);
    }
};
