<?php

use Illuminate\Support\Facades\Route;

Route::middleware('throttle:asset-source')->group(function () {
    Route::get('{player}.json', 'TextureController@json');
    Route::get('csl/{player}.json', 'TextureController@json');
    Route::get('textures/{hash}', 'TextureController@texture')->where('hash', '[0-9a-f]{64}');
    Route::get('csl/textures/{hash}', 'TextureController@texture')->where('hash', '[0-9a-f]{64}');

    Route::middleware('web')->group(function () {
        Route::get('raw/{tid}', 'TextureController@raw')->whereNumber('tid');
        Route::get('asset/{texture}', 'TextureController@asset')->whereNumber('texture')->name('texture.asset');
    });
});

Route::middleware('throttle:asset-render')->group(function () {
    Route::prefix('avatar')->name('avatar.')->group(function () {
        Route::get('player/{name}', 'TextureController@avatarByPlayer')->name('player');
        Route::get('user/{uid}', 'TextureController@avatarByUser')->whereNumber('uid')->name('user');
        Route::get('hash/{hash}', 'TextureController@avatarByHash')->where('hash', '[0-9a-f]{64}')->name('hash');
        Route::get('{tid}', 'TextureController@avatarByTexture')->whereNumber('tid')->middleware('web')->name('texture');
    });

    Route::prefix('preview')->name('preview.')->group(function () {
        Route::get('hash/{hash}', 'TextureController@previewByHash')->where('hash', '[0-9a-f]{64}')->name('hash');
        Route::get('{texture}', 'TextureController@preview')->whereNumber('texture')->middleware('web')->name('texture');
    });
});

Route::get('plugin-assets/{name}/{digest}/{asset}', 'PluginController@asset')
    ->where(['name' => '[a-zA-Z0-9_-]+', 'digest' => '[0-9a-f]{64}', 'asset' => '.+'])
    ->middleware('throttle:asset-source')->name('plugin.asset');
