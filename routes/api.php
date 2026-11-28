<?php

use App\Http\Controllers\Api\AccountApiController as Me;
use App\Http\Controllers\Api\RegisterApiController as Api;
use App\Http\Controllers\DiscoveryController;
use App\Http\Middleware\RequireOAuthKeys;
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

// The signed-in person's watches, with an OAuth access token (Bearer) from
// Pegwatch: see /.well-known/oauth-authorization-server.
Route::prefix('v1/me')->middleware(['throttle:agents', RequireOAuthKeys::class, 'auth:api'])->name('api.me.')->group(function () {
    Route::get('watches', [Me::class, 'index'])->name('watches');
    Route::post('watches', [Me::class, 'store'])->name('watches.store');
    Route::get('watches/{id}', [Me::class, 'show'])->whereNumber('id')->name('watches.show');
    Route::patch('watches/{id}', [Me::class, 'update'])->whereNumber('id')->name('watches.update');
    Route::post('watches/{id}/stop', [Me::class, 'stop'])->whereNumber('id')->name('watches.stop');
    Route::post('watches/{id}/resume', [Me::class, 'resume'])->whereNumber('id')->name('watches.resume');
    Route::post('watches/{id}/confirm', [Me::class, 'confirm'])->whereNumber('id')->name('watches.confirm');
    Route::delete('watches/{id}', [Me::class, 'destroy'])->whereNumber('id')->name('watches.destroy');
});
