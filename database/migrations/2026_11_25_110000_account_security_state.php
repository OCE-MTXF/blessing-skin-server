<?php

use App\Services\Auth\AccountIdentity;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // MySQL DDL can commit automatically. Resume an interrupted upgrade rather than
        // overwriting records; refuse to choose winners among existing identity collisions.
        if (!Schema::hasColumn('users', 'email_key')) {
            Schema::table('users', fn (Blueprint $table) => $table->char('email_key', 64)->nullable());
        }
        DB::table('users')->select('uid', 'email')->chunkById(500, function ($rows) {
            foreach ($rows as $row) {
                DB::table('users')->where('uid', $row->uid)->update([
                    'email_key' => AccountIdentity::emailKey($row->email),
                ]);
            }
        }, 'uid');
        if (DB::table('users')->whereNotNull('email_key')->select('email_key')->groupBy('email_key')->havingRaw('COUNT(*) > 1')->exists()) {
            throw new RuntimeException('Duplicate normalized account email addresses. Review and resolve them in a private migration preflight.');
        }
        if (DB::table('players')->select('name')->groupBy('name')->havingRaw('COUNT(*) > 1')->exists()) {
            throw new RuntimeException('Duplicate player names. Review and resolve them in a private migration preflight.');
        }
        if (!Schema::hasIndex('users', ['email_key'], 'unique')) {
            // Unbound plugin-imported accounts may all have NULL keys.
            if (DB::getDriverName() === 'sqlsrv') {
                $users = DB::connection()->getQueryGrammar()->wrapTable('users');
                DB::statement("CREATE UNIQUE INDEX users_email_key_unique ON $users (email_key) WHERE email_key IS NOT NULL");
            } else {
                Schema::table('users', fn (Blueprint $table) => $table->unique('email_key'));
            }
        }
        if (!Schema::hasIndex('players', ['name'], 'unique')) {
            Schema::table('players', fn (Blueprint $table) => $table->unique('name'));
        }
        if (!Schema::hasColumn('users', 'account_version')) {
            Schema::table('users', fn (Blueprint $table) => $table->unsignedBigInteger('account_version')->default(1));
        }
        if (!Schema::hasTable('account_action_tokens')) {
            Schema::create('account_action_tokens', function (Blueprint $table) {
                $table->char('digest', 64)->primary();
                $table->unsignedInteger('user_id');
                $table->string('purpose', 16);
                $table->char('email_digest', 64);
                $table->unsignedBigInteger('account_version');
                $table->dateTime('expires_at');
                $table->dateTime('consumed_at')->nullable();
                $table->unique(['user_id', 'purpose']);
                $table->index('expires_at');
            });
        }
        if (!Schema::hasTable('account_rate_limits')) {
            Schema::create('account_rate_limits', function (Blueprint $table) {
                $table->char('bucket', 64)->primary();
                $table->unsignedInteger('attempts');
                $table->unsignedBigInteger('expires_at')->index();
            });
        }
        if (!Schema::hasTable('account_registration_sources')) {
            // This lock row serializes the quota check over users.ip even on an empty source.
            Schema::create('account_registration_sources', function (Blueprint $table) {
                $table->string('ip', 45)->primary();
            });
        }
        if (!Schema::hasIndex('users', ['ip'])) {
            Schema::table('users', fn (Blueprint $table) => $table->index('ip'));
        }
        if (!Schema::hasTable('account_security_mutex')) {
            Schema::create('account_security_mutex', fn (Blueprint $table) => $table->unsignedTinyInteger('id')->primary());
        }
        DB::table('account_security_mutex')->insertOrIgnore(['id' => 1]);

        // Older sessions have no epoch marker. Also invalidate legacy remember
        // cookies (otherwise a recall could restamp them) and old email challenges.
        DB::table('users')->whereNotNull('remember_token')->update(['remember_token' => null]);
        DB::table('users')->where('verification_token', '!=', '')->update(['verification_token' => '']);
    }
};
