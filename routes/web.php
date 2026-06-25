<?php

use Illuminate\Support\Facades\Route;

Route::view('/', 'welcome')->name('home');

Route::middleware(['auth', 'verified'])->group(function () {
    Route::view('dashboard', 'dashboard')->name('dashboard');
    Route::livewire('dishes', 'pages::dishes')->name('dishes.index');
    Route::livewire('dishes/import', 'pages::dish-import')->name('dishes.import');
    Route::livewire('ingredients', 'pages::ingredients')->name('ingredients.index');
    Route::livewire('families', 'pages::families')->name('families.index');
    Route::livewire('families/members', 'pages::family-members')->name('family-members.index');
    Route::livewire('families/invitations', 'pages::family-invitations')->name('family-invitations.index');
    Route::livewire('family-invitations/{token}', 'pages::family-invitation')->name('family-invitations.show');
});

require __DIR__.'/settings.php';
