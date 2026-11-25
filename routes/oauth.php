<?php

use App\Services\Auth\OAuthAuthorizationController;
use App\Services\Auth\OAuthBoundary;
use App\Services\Auth\OAuthClientController;
use Illuminate\Support\Facades\Route;
use Laravel\Passport\Http\Controllers\AccessTokenController;
use Laravel\Passport\Http\Controllers\ApproveAuthorizationController;
use Laravel\Passport\Http\Controllers\AuthorizedAccessTokenController;
use Laravel\Passport\Http\Controllers\DenyAuthorizationController;
use Laravel\Passport\Http\Controllers\PersonalAccessTokenController;
use Laravel\Passport\Http\Controllers\ScopeController;

Route::prefix('oauth')->name('passport.')->group(function () {
    Route::post('token', [AccessTokenController::class, 'issueToken'])
        ->middleware(['throttle:api', OAuthBoundary::class.':token'])->name('token');
    Route::get('authorize', OAuthAuthorizationController::class)
        ->middleware(['web', 'throttle:api', OAuthBoundary::class.':authorize'])->name('authorizations.authorize');

    Route::middleware(['web', 'throttle:api', 'authorize', 'verified'])->group(function () {
        Route::post('authorize', [ApproveAuthorizationController::class, 'approve'])
            ->middleware(OAuthBoundary::class.':consent')->name('authorizations.approve');
        Route::delete('authorize', [DenyAuthorizationController::class, 'deny'])
            ->middleware(OAuthBoundary::class.':consent')->name('authorizations.deny');

        Route::get('scopes', [ScopeController::class, 'all'])->name('scopes.index');
        Route::get('clients', [OAuthClientController::class, 'index'])->name('clients.index');
        Route::get('tokens', [AuthorizedAccessTokenController::class, 'forUser'])->name('tokens.index');
        Route::get('personal-access-tokens', [PersonalAccessTokenController::class, 'forUser'])->name('personal.tokens.index');

        Route::middleware(OAuthBoundary::class.':web')->group(function () {
            Route::post('clients', [OAuthClientController::class, 'store'])->name('clients.store');
            Route::put('clients/{client_id}', [OAuthClientController::class, 'update'])->whereNumber('client_id')->name('clients.update');
            Route::delete('clients/{client_id}', [OAuthClientController::class, 'destroy'])->whereNumber('client_id')->name('clients.destroy');
            Route::delete('tokens/{token_id}', [AuthorizedAccessTokenController::class, 'destroy'])->name('tokens.destroy');
            Route::post('personal-access-tokens', [PersonalAccessTokenController::class, 'store'])->name('personal.tokens.store');
            Route::delete('personal-access-tokens/{token_id}', [AuthorizedAccessTokenController::class, 'destroy'])->name('personal.tokens.destroy');
        });
    });
});
