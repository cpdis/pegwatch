<?php

use App\Http\Controllers\DigestUnsubscribeController;
use App\Http\Controllers\IngestHookController;
use App\Http\Controllers\StripeWebhookController;
use App\Http\Controllers\TileController;
use Illuminate\Foundation\Http\Middleware\PreventRequestForgery;
use Illuminate\Support\Facades\Route;

Route::livewire('/', 'pages::map')->name('home');
Route::livewire('activity', 'pages::activity')->name('activity');
Route::livewire('tenements/{slug}', 'pages::tenements.show')->name('tenements.show');
Route::livewire('holders/{slug}', 'pages::holders.show')->name('holders.show');

Route::livewire('digest', 'pages::digest.index')->name('digest');
Route::livewire('digest/confirm/{token}', 'pages::digest.confirm')->name('digest.confirm');
Route::livewire('digest/unsubscribe/{token}', 'pages::digest.unsubscribe')->name('digest.unsubscribe');
Route::post('digest/unsubscribe/{token}', DigestUnsubscribeController::class)
    ->withoutMiddleware([PreventRequestForgery::class])->name('digest.unsubscribe.one-click');
Route::livewire('digest/{week}', 'pages::digest.issue')->where('week', '[0-9]{4}-W[0-9]{2}')->name('digest.issue');

Route::livewire('pro', 'pages::pro')->name('pro');
Route::post('hooks/stripe', StripeWebhookController::class)
    ->withoutMiddleware([PreventRequestForgery::class])->name('hooks.stripe');

Route::get('tiles/{layer}/{z}/{x}/{y}.mvt', [TileController::class, 'show'])
    ->where(['z' => '[0-9]{1,2}', 'x' => '[0-9]{1,7}', 'y' => '[0-9]{1,7}'])
    // No session or cookies: tiles are public, and a cookie stops the CDN caching them.
    ->withoutMiddleware('web')->name('tiles');

Route::post('hooks/ingest-complete', IngestHookController::class)
    ->withoutMiddleware([PreventRequestForgery::class])->name('hooks.ingest');

// g-visual: the data status page.
Route::livewire('data', 'pages::data')->name('data');

Route::middleware(['auth', 'verified'])->group(function () {
    Route::redirect('dashboard', '/watch-areas')->name('dashboard');
    Route::livewire('watch-areas', 'pages::watch-areas')->name('watch-areas.index');
});

require __DIR__.'/settings.php';
