<?php

use Illuminate\Support\Facades\Route;

Route::view('/', 'welcome')->name('home');

Route::middleware(['auth', 'verified'])->group(function () {
    Route::view('dashboard', 'dashboard')->name('dashboard');
    Route::livewire('families', 'pages::families')->name('families.index');
    Route::livewire('families/invitations', 'pages::family-invitations')->name('family-invitations.index');
    Route::livewire('family-invitations/{token}', 'pages::family-invitation')->name('family-invitations.show');
});

require __DIR__.'/settings.php';
