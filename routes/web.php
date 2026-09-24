<?php

use Illuminate\Support\Facades\Route;

Route::view('/', 'welcome')->name('home');

Route::middleware(['auth'])->group(function () {
    Route::livewire('dashboard', 'pages::dashboard')->name('dashboard');

    Route::livewire('mieszkania', 'pages::apartments.index')->name('apartments.index');
    Route::livewire('mieszkania/dodaj', 'pages::apartments.create')->name('apartments.create');

    Route::prefix('mieszkania/{apartment}')->middleware('can:view,apartment')->group(function () {
        Route::livewire('/', 'pages::apartments.show')->name('apartments.show');
        Route::livewire('edytuj', 'pages::apartments.edit')->name('apartments.edit');
        Route::livewire('wlasciciele', 'pages::apartments.owners')->name('apartments.owners');
        Route::livewire('historia', 'pages::apartments.history')->name('apartments.history');
    });
});

Route::livewire('zaproszenie/{token}', 'pages::invitations.show')->name('invitations.show');

require __DIR__.'/settings.php';
