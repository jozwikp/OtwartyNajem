<?php

use App\Models\Apartment;
use App\Models\Bill;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\Storage;

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

        Route::livewire('najem', 'pages::leases.show')->name('leases.current');
        Route::livewire('najem/nowy', 'pages::leases.create')->name('leases.create');
        Route::livewire('rozliczenia', 'pages::leases.ledger')->name('leases.ledger.current');

        Route::scopeBindings()->group(function () {
            Route::livewire('najmy/{lease}', 'pages::leases.show')->name('leases.show');
            Route::livewire('najmy/{lease}/edytuj', 'pages::leases.edit')->name('leases.edit');
            Route::livewire('najmy/{lease}/rozliczenia', 'pages::leases.ledger')->name('leases.ledger');
            Route::livewire('najmy/{lease}/rachunki/dodaj', 'pages::bills.create')->name('bills.create');

            Route::get('rachunki/{bill}/plik', function (Apartment $apartment, Bill $bill) {
                return Storage::disk('local')->response($bill->file_path, $bill->file_name);
            })->name('bills.file');
        });
    });
});

Route::livewire('zaproszenie/{token}', 'pages::invitations.show')->name('invitations.show');

require __DIR__.'/settings.php';
