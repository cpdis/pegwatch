<?php

use App\Http\Controllers\ActivityController;
use App\Http\Controllers\HolderController;
use App\Http\Controllers\IngestHookController;
use App\Http\Controllers\MapController;
use App\Http\Controllers\TenementController;
use App\Http\Controllers\TileController;
use App\Http\Controllers\WatchAreaController;
use Illuminate\Foundation\Http\Middleware\ValidateCsrfToken;
use Illuminate\Support\Facades\Route;

Route::get('/', [MapController::class, 'index'])->name('home');
Route::get('/search', [MapController::class, 'search'])->name('search');
Route::get('/activity', [ActivityController::class, 'index'])->name('activity');
Route::get('/tenements/{slug}', [TenementController::class, 'show'])->name('tenements.show');
Route::get('/holders/{slug}', [HolderController::class, 'show'])->name('holders.show');
Route::get('/tiles/{layer}/{z}/{x}/{y}.mvt', [TileController::class, 'show'])
    ->whereNumber(['z', 'x', 'y'])->name('tiles');

Route::post('/hooks/ingest-complete', IngestHookController::class)
    ->withoutMiddleware([ValidateCsrfToken::class])->name('hooks.ingest');

Route::middleware(['auth'])->group(function () {
    Route::redirect('dashboard', '/watch-areas')->name('dashboard');
    Route::get('watch-areas', [WatchAreaController::class, 'index'])->name('watch-areas.index');
    Route::post('watch-areas', [WatchAreaController::class, 'store'])->name('watch-areas.store');
    Route::delete('watch-areas/{watchArea}', [WatchAreaController::class, 'destroy'])->name('watch-areas.destroy');
});

require __DIR__.'/settings.php';
require __DIR__.'/auth.php';
