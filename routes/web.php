<?php

use App\Mail\TenantDigestMail;
use App\Models\Apartment;
use App\Models\Bill;
use App\Models\Lease;
use App\Support\EncryptedFiles;
use App\Support\Locales;
use App\Support\TenantDigest;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Str;
use Symfony\Component\HttpFoundation\HeaderUtils;

Route::view('/', 'welcome')->name('home');

Route::middleware(['auth'])->group(function () {
    Route::livewire('dashboard', 'pages::dashboard')->name('dashboard');

    Route::livewire('wplaty', 'pages::payments.index')->name('payments.index');
    Route::livewire('rachunki', 'pages::bills.index')->name('bills.index');
    Route::livewire('rachunki/dodaj', 'pages::bills.create')->name('bills.create');
    Route::livewire('rachunki/{bill}', 'pages::bills.edit')->middleware('can:update,bill')->name('bills.edit');
    Route::get('rachunki/{bill}/plik', function (Bill $bill) {
        abort_unless($bill->hasFile(), 404);

        return response(EncryptedFiles::get($bill->file_path), 200, [
            'Content-Type' => $bill->file_mime ?? 'application/octet-stream',
            'Content-Disposition' => HeaderUtils::makeDisposition(HeaderUtils::DISPOSITION_INLINE, $bill->file_name, Str::ascii($bill->file_name)),
            'X-Content-Type-Options' => 'nosniff',
        ]);
    })->middleware('can:view,bill')->name('bills.file');

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
            Route::get('najmy/{lease}/powiadomienie', function (Apartment $apartment, Lease $lease) {
                $locale = in_array(request('lang'), Locales::codes(), true) ? request('lang') : ($lease->primaryTenant->locale ?? 'pl');

                return (new TenantDigestMail(new TenantDigest($lease), $lease->tenants->where('locale', $locale)->values()))->locale($locale);
            })
                ->name('leases.notification-preview');
        });
    });
});

Route::livewire('zaproszenie/{token}', 'pages::invitations.show')->name('invitations.show');

require __DIR__.'/settings.php';
