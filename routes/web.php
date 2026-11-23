<?php

use App\Http\Controllers\IngestHookController;
use App\Http\Controllers\TileController;
use Illuminate\Foundation\Http\Middleware\PreventRequestForgery;
use Illuminate\Support\Facades\Route;

Route::livewire('/', 'pages::map')->name('home');
Route::livewire('activity', 'pages::activity')->name('activity');
Route::livewire('tenements/{slug}', 'pages::tenements.show')->name('tenements.show');
Route::livewire('holders/{slug}', 'pages::holders.show')->name('holders.show');

Route::get('tiles/{layer}/{z}/{x}/{y}.mvt', [TileController::class, 'show'])
    ->where(['z' => '[0-9]{1,2}', 'x' => '[0-9]{1,7}', 'y' => '[0-9]{1,7}'])
    // No session or cookies: tiles are public, and a cookie stops the CDN caching them.
    ->withoutMiddleware('web')->name('tiles');

Route::post('hooks/ingest-complete', IngestHookController::class)
    ->withoutMiddleware([PreventRequestForgery::class])->name('hooks.ingest');

Route::middleware(['auth', 'verified'])->group(function () {
    Route::redirect('dashboard', '/watch-areas')->name('dashboard');
    Route::livewire('watch-areas', 'pages::watch-areas')->name('watch-areas.index');
});

require __DIR__.'/settings.php';
