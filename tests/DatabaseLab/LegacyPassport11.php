<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\MigrationRepositoryInterface;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// Schema fixture matching Passport v11.8.4 at b6b68fad1d02e39c6c659705159487f643393cdd.
// It models the vendor migrations present in the Git baseline's composer.lock without
// installing or running the old vendor or application on the new PHP runtime.
return static function (MigrationRepositoryInterface $ledger): void {
    Schema::create('oauth_auth_codes', static function (Blueprint $table): void {
        $table->string('id', 100)->primary();
        $table->unsignedBigInteger('user_id')->index();
        $table->unsignedBigInteger('client_id');
        $table->text('scopes')->nullable();
        $table->boolean('revoked');
        $table->dateTime('expires_at')->nullable();
    });
    Schema::create('oauth_access_tokens', static function (Blueprint $table): void {
        $table->string('id', 100)->primary();
        $table->unsignedBigInteger('user_id')->nullable()->index();
        $table->unsignedBigInteger('client_id');
        $table->string('name')->nullable();
        $table->text('scopes')->nullable();
        $table->boolean('revoked');
        $table->timestamps();
        $table->dateTime('expires_at')->nullable();
    });
    Schema::create('oauth_refresh_tokens', static function (Blueprint $table): void {
        $table->string('id', 100)->primary();
        $table->string('access_token_id', 100)->index();
        $table->boolean('revoked');
        $table->dateTime('expires_at')->nullable();
    });
    Schema::create('oauth_clients', static function (Blueprint $table): void {
        $table->bigIncrements('id');
        $table->unsignedBigInteger('user_id')->nullable()->index();
        $table->string('name');
        $table->string('secret', 100)->nullable();
        $table->string('provider')->nullable();
        $table->text('redirect');
        $table->boolean('personal_access_client');
        $table->boolean('password_client');
        $table->boolean('revoked');
        $table->timestamps();
    });
    Schema::create('oauth_personal_access_clients', static function (Blueprint $table): void {
        $table->bigIncrements('id');
        $table->unsignedBigInteger('client_id');
        $table->timestamps();
    });
    foreach (['auth_codes', 'access_tokens', 'refresh_tokens', 'clients', 'personal_access_clients'] as $index => $table) {
        $ledger->log(sprintf('2016_06_01_%06d_create_oauth_%s_table', $index + 1, $table), 1);
    }
};
