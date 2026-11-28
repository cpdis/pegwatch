<?php

use App\Http\Middleware\RequirePasswordIfSet;
use Illuminate\Support\Facades\Route;

Route::middleware(['auth'])->group(function () {
    Route::redirect('settings', 'settings/profile');

    Route::livewire('settings/profile', 'pages::settings.profile')->name('profile.edit');
    Route::livewire('settings/agents', 'pages::settings.agents')->name('connected-agents.edit');
});

Route::middleware(['auth', 'verified'])->group(function () {
    Route::livewire('settings/appearance', 'pages::settings.appearance')->name('appearance.edit');

    Route::livewire('settings/security', 'pages::settings.security')
        // Accounts without a password (emailed-link sign-in) have nothing to confirm.
        ->middleware([RequirePasswordIfSet::class])
        ->name('security.edit');
});
