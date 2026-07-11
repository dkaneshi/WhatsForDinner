<?php

use App\Http\Controllers\Auth\WorkOSCallbackController;
use App\Http\Controllers\Auth\WorkOSConfirmController;
use App\Http\Controllers\Auth\WorkOSRedirectController;
use Illuminate\Support\Facades\Route;

Route::view('/', 'welcome')->name('home');

Route::get('auth/workos/redirect', WorkOSRedirectController::class)
    ->middleware(['guest', 'throttle:workos-auth'])
    ->name('workos.redirect');

Route::get('auth/workos/callback', WorkOSCallbackController::class)
    ->middleware('throttle:workos-callback')
    ->name('workos.callback');

Route::post('auth/workos/confirm', WorkOSConfirmController::class)
    ->middleware(['auth', 'verified', 'throttle:workos-confirm'])
    ->name('workos.confirm');

Route::middleware(['auth', 'verified'])->group(function () {
    Route::livewire('dashboard', 'pages::dashboard')->name('dashboard');
    Route::livewire('plans/{weekStart?}', 'pages::weekly-plan')->name('weekly-plans.show');
    Route::livewire('groceries/{weekStart?}', 'pages::grocery-list')->name('grocery-lists.show');
    Route::livewire('dishes', 'pages::dishes')->name('dishes.index');
    Route::livewire('dishes/import', 'pages::dish-import')->name('dishes.import');
    Route::livewire('ingredients', 'pages::ingredients')->name('ingredients.index');
    Route::livewire('families', 'pages::families')->name('families.index');
    Route::livewire('families/members', 'pages::family-members')->name('family-members.index');
    Route::livewire('families/invitations', 'pages::family-invitations')->name('family-invitations.index');
    Route::livewire('family-invitations/{token}', 'pages::family-invitation')->name('family-invitations.show');
});

require __DIR__.'/settings.php';
