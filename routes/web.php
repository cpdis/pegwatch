<?php

use App\Http\Controllers\ActivityRssController;
use App\Http\Controllers\DigestUnsubscribeController;
use App\Http\Controllers\DiscoveryController as Discovery;
use App\Http\Controllers\IngestHookController;
use App\Http\Controllers\LoginLinkController;
use App\Http\Controllers\OgImageController;
use App\Http\Controllers\ReleaseIcsController;
use App\Http\Controllers\StripeWebhookController;
use App\Http\Controllers\TileController;
use App\Http\Controllers\WatchLinkController;
use Illuminate\Foundation\Http\Middleware\PreventRequestForgery;
use Illuminate\Support\Facades\Route;

Route::livewire('/', 'pages::map')->name('home');
Route::livewire('activity', 'pages::activity')->name('activity');
Route::livewire('tenements/{slug}', 'pages::tenements.show')->name('tenements.show');
Route::livewire('holders/{slug}', 'pages::holders.show')->name('holders.show');

// d-holders: companies whose names match ("Entities matching NORTHERN STAR").
Route::livewire('holders/matching/{slug}', 'pages::holders.matching')->name('holders.matching');

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

// c-tenement
Route::get('tenements/{slug}/release.ics', ReleaseIcsController::class)->name('tenements.release-ics');

Route::post('hooks/ingest-complete', IngestHookController::class)
    ->withoutMiddleware([PreventRequestForgery::class])->name('hooks.ingest');

// g-visual: the data status page and share images. The images carry no
// session or cookies (so a CDN can cache them); the area image needs a
// signed URL.
Route::livewire('data', 'pages::data')->name('data');
Route::livewire('how-people-use-pegwatch', 'pages::use-cases')->name('use-cases');
Route::get('og/tenements/{slug}.png', [OgImageController::class, 'tenement'])
    ->where('slug', '[a-z0-9-]+')->withoutMiddleware('web')->name('og.tenement');
Route::get('og/areas/{watchArea}.png', [OgImageController::class, 'area'])
    ->whereNumber('watchArea')->withoutMiddleware('web')->middleware('signed')->name('og.area');

// e-activity
// Public and cookie-free, like the tiles, so a CDN can cache it.
Route::get('activity.rss', ActivityRssController::class)->withoutMiddleware('web')->name('activity.rss');

// f-accounts: email-first watching, sign-in by emailed link, alert unsubscribe.
// Every emailed link opens a page with one button; only the POST acts.
Route::livewire('watch', 'pages::watch')->name('watch.start');
Route::get('watch/confirm/{watchArea}', [WatchLinkController::class, 'show'])->whereNumber('watchArea')->name('watch.confirm');
Route::post('watch/confirm/{watchArea}', [WatchLinkController::class, 'confirm'])->whereNumber('watchArea')->name('watch.confirm.store');
Route::get('watch-areas/{watchArea}/stop', [WatchLinkController::class, 'showStop'])->whereNumber('watchArea')->name('watch-areas.stop');
Route::post('watch-areas/{watchArea}/stop', [WatchLinkController::class, 'stop'])->whereNumber('watchArea')
    ->withoutMiddleware([PreventRequestForgery::class])->name('watch-areas.stop.one-click');
Route::middleware('guest')->group(function () {
    // Sign-up is email only, so it replaces Fortify's password registration.
    Route::view('register', 'pages::auth.register')->name('register');
    Route::post('login/link', [LoginLinkController::class, 'send'])->name('login.link.send');
});
Route::get('login/link/{token}', [LoginLinkController::class, 'show'])->name('login.link');
Route::post('login/link/{token}', [LoginLinkController::class, 'store'])->name('login.link.store');
Route::middleware(['auth', 'verified'])->group(function () {
    Route::get('watch-areas/{watchArea}/preview', [WatchLinkController::class, 'preview'])->whereNumber('watchArea')->name('watch-areas.preview');
});

Route::middleware(['auth', 'verified'])->group(function () {
    Route::redirect('dashboard', '/watch-areas')->name('dashboard');
    Route::livewire('watch-areas', 'pages::watch-areas')->name('watch-areas.index');
});

// Agents: the guide page, and the cookie-free discovery files (robots.txt,
// sitemaps, llms.txt, /.well-known). The API is routes/api.php; the MCP
// server is routes/ai.php.
Route::livewire('agents', 'pages::agents')->name('agents');
Route::withoutMiddleware('web')->group(function () {
    Route::get('robots.txt', [Discovery::class, 'robots'])->name('robots');
    Route::get('sitemap.xml', [Discovery::class, 'sitemapIndex'])->name('sitemap');
    Route::get('sitemaps/{part}.xml', [Discovery::class, 'sitemap'])->where('part', '[a-z]+(-[0-9]+)?')->name('sitemap.part');
    Route::get('llms.txt', [Discovery::class, 'llms'])->name('llms');
    Route::get('llms-full.txt', [Discovery::class, 'llmsFull'])->name('llms-full');
    Route::get('auth.md', [Discovery::class, 'authMd'])->name('auth-md');
    Route::get('.well-known/api-catalog', [Discovery::class, 'apiCatalog'])->name('wellknown.api-catalog');
    Route::get('.well-known/mcp/server-card.json', [Discovery::class, 'mcpServerCard'])->name('wellknown.mcp-card');
    Route::get('.well-known/agent-skills/index.json', [Discovery::class, 'skillsIndex'])->name('wellknown.skills');
    Route::get('.well-known/agent-skills/{name}/SKILL.md', [Discovery::class, 'skill'])->where('name', '[a-z0-9-]+')->name('wellknown.skill');
    Route::get('.well-known/ai-catalog.json', [Discovery::class, 'aiCatalog'])->name('wellknown.ai-catalog');
});

require __DIR__.'/settings.php';
