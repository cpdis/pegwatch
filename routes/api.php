<?php

use App\Http\Controllers\Api\RegisterApiController as Api;
use App\Http\Controllers\DiscoveryController;
use Illuminate\Support\Facades\Route;

// The public JSON API, described by /api/openapi.json. No key; rate limited
// per client. Prefixed /api by bootstrap/app.php.

Route::get('openapi.json', [DiscoveryController::class, 'openapi'])->name('api.openapi');

Route::prefix('v1')->middleware('throttle:agents')->name('api.')->group(function () {
    Route::get('search', [Api::class, 'search'])->name('search');
    Route::get('tenements', [Api::class, 'tenements'])->name('tenements');
    Route::get('tenements/at', [Api::class, 'at'])->name('tenements.at');
    Route::get('tenements/{id}', [Api::class, 'tenement'])->where('id', '.{1,40}')->name('tenement');
    Route::get('holders/{slug}', [Api::class, 'holder'])->where('slug', '.{1,200}')->name('holder');
    Route::get('activity', [Api::class, 'activity'])->name('activity');
    Route::get('releases', [Api::class, 'releases'])->name('releases');
    Route::get('status', [Api::class, 'status'])->name('status');
    Route::get('digest/latest', [Api::class, 'digest'])->name('digest.latest');
    Route::get('digest/{week}', [Api::class, 'digest'])->where('week', '[0-9]{4}-W[0-9]{2}')->name('digest');
    Route::get('watches/preview', [Api::class, 'watchPreview'])->name('watches.preview');
    // These two send one confirm email each; the owner's press of its link is the approval.
    Route::post('watches', [Api::class, 'watch'])->middleware('throttle:agent-signups')->name('watches.request');
    Route::post('digest/subscriptions', [Api::class, 'digestSubscribe'])->middleware('throttle:agent-signups')->name('digest.subscribe');
});
