<?php

use App\Models\User;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (config('passport.connection') !== null && config('passport.connection') !== config('database.default')) {
            throw new RuntimeException('Passport and accounts must use the same database connection.');
        }

        if (!Schema::hasTable('oauth_clients')) {
            // Retain the application's pre-13 integer client IDs and URLs.
            Schema::create('oauth_clients', function (Blueprint $table) {
                $table->bigIncrements('id');
                $table->nullableMorphs('owner');
                $table->string('name');
                $table->string('secret')->nullable();
                $table->string('provider')->nullable();
                $table->text('redirect_uris');
                $table->text('grant_types');
                $table->boolean('revoked');
                $table->timestamps();
            });
        } else {
            $this->upgradeClients();
        }
        if (!Schema::hasTable('oauth_auth_codes')) {
            Schema::create('oauth_auth_codes', function (Blueprint $table) {
                $table->char('id', 80)->primary();
                $table->unsignedBigInteger('user_id')->index();
                $table->unsignedBigInteger('client_id')->index();
                $table->text('scopes')->nullable();
                $table->boolean('revoked');
                $table->dateTime('expires_at')->nullable();
            });
        }
        if (!Schema::hasTable('oauth_access_tokens')) {
            Schema::create('oauth_access_tokens', function (Blueprint $table) {
                $table->char('id', 80)->primary();
                $table->unsignedBigInteger('user_id')->nullable()->index();
                $table->unsignedBigInteger('client_id')->index();
                $table->string('name')->nullable();
                $table->text('scopes')->nullable();
                $table->boolean('revoked');
                $table->timestamps();
                $table->dateTime('expires_at')->nullable();
            });
        }
        if (!Schema::hasTable('oauth_refresh_tokens')) {
            Schema::create('oauth_refresh_tokens', function (Blueprint $table) {
                $table->char('id', 80)->primary();
                $table->char('access_token_id', 80)->index();
                $table->boolean('revoked');
                $table->dateTime('expires_at')->nullable();
            });
        }

        // One-time explicit re-consent. Do not translate Closet.ReadWrtie, wildcard,
        // obsolete grants, or old bearer semantics into new permissions.
        DB::transaction(function () {
            foreach (['oauth_auth_codes', 'oauth_refresh_tokens', 'oauth_access_tokens'] as $table) {
                DB::table($table)->where('revoked', false)->update(['revoked' => true]);
            }
        });
    }

    private function upgradeClients(): void
    {
        $idType = Schema::getColumnType('oauth_clients', 'id');
        // PostgreSQL reports catalog names int4/int8 via getColumnType().
        if (!in_array($idType, ['integer', 'int', 'bigint', 'int4', 'int8'], true)) {
            throw new RuntimeException('Nonstandard Passport client ID schema. Review the client schema on a private migration clone.');
        }
        if (!Schema::hasColumn('oauth_clients', 'owner_type')) {
            Schema::table('oauth_clients', fn (Blueprint $table) => $table->nullableMorphs('owner'));
        }
        foreach (['redirect_uris', 'grant_types'] as $column) {
            if (!Schema::hasColumn('oauth_clients', $column)) {
                Schema::table('oauth_clients', fn (Blueprint $table) => $table->text($column)->nullable());
            }
        }
        if (!Schema::hasColumn('oauth_clients', 'provider')) {
            Schema::table('oauth_clients', fn (Blueprint $table) => $table->string('provider')->nullable());
        }

        DB::table('oauth_clients')->chunkById(200, function ($clients) {
            foreach ($clients as $client) {
                try {
                    $redirects = $client->redirect_uris !== null ? json_decode($client->redirect_uris, true, 512, JSON_THROW_ON_ERROR)
                        : array_values(array_filter(explode(',', $client->redirect ?? ''), 'strlen'));
                    $grants = $client->grant_types !== null ? json_decode($client->grant_types, true, 512, JSON_THROW_ON_ERROR)
                        : array_merge($redirects === [] ? [] : ['authorization_code', 'refresh_token'],
                            !empty($client->personal_access_client) && !empty($client->secret) ? ['personal_access'] : []);
                    $secret = $client->secret;
                    if ($secret !== null && password_get_info($secret)['algo'] === null) {
                        $secret = $secret === '' ? null : Hash::make($secret);
                    }
                    $ownerId = $client->owner_id ?? $client->user_id ?? null;
                    DB::table('oauth_clients')->where('id', $client->id)->update([
                        'owner_id' => $ownerId,
                        'owner_type' => $client->owner_type ?? ($ownerId ? User::class : null),
                        'redirect_uris' => json_encode($redirects, JSON_THROW_ON_ERROR),
                        'grant_types' => json_encode(array_values(array_intersect($grants, ['authorization_code', 'refresh_token', 'personal_access'])), JSON_THROW_ON_ERROR),
                        'secret' => $secret,
                        'provider' => $client->provider ?: 'users',
                    ]);
                } catch (Throwable) {
                    throw new RuntimeException('Unable to migrate an OAuth client; verify legacy fields and the hashing configuration on a private clone.');
                }
            }
        });
        $obsolete = array_filter(['user_id', 'redirect', 'personal_access_client', 'password_client'],
            fn ($column) => Schema::hasColumn('oauth_clients', $column));
        if ($obsolete !== []) {
            if (in_array('user_id', $obsolete, true) && Schema::hasIndex('oauth_clients', ['user_id'])) {
                Schema::table('oauth_clients', fn (Blueprint $table) => $table->dropIndex(['user_id']));
            }
            Schema::table('oauth_clients', fn (Blueprint $table) => $table->dropColumn(array_values($obsolete)));
        }
    }
};
