<?php

use App\Actions\Leases\CreateLease;
use App\Enums\ChargeType;
use App\Mail\TenantDigestMail;
use App\Models\Apartment;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;
use Livewire\Livewire;

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->travelTo(CarbonImmutable::parse('2026-09-24 12:00'));
});

function ownerWithLease(string $locale): array
{
    $user = User::factory()->create(['locale' => $locale, 'name' => 'Anna Nowak']);
    $apartment = Apartment::factory()->ownedBy($user)->create(['label' => 'Kawalerka Mokotów', 'country_code' => 'DE']);
    $lease = app(CreateLease::class)->handle(
        $apartment, $user,
        ['starts_on' => '2026-09-01', 'ends_on' => null, 'currency' => 'PLN', 'payment_due_day' => 10],
        [['first_name' => 'Jan', 'last_name' => 'Kowalski', 'email' => 'jan@example.com', 'phone' => null]],
        [['type' => ChargeType::Rent, 'name' => null, 'amount' => 250000]],
    );

    return [$user, $apartment, $lease];
}

test('each user sees the interface in their own language', function () {
    [$en, $apartment, $lease] = ownerWithLease('en');

    $this->actingAs($en)->get(route('apartments.index'))
        ->assertOk()
        ->assertSee('<html lang="en"', false)
        ->assertSee('My apartments')
        ->assertSee('Add apartment')
        ->assertDontSee('Moje mieszkania');

    $this->get(route('leases.ledger', [$apartment, $lease]))
        ->assertSee('To pay: PLN'."\u{00A0}".'2,500')
        ->assertSee('Record payment');

    $this->get(route('apartments.show', $apartment))->assertSee('Germany');

    $pl = User::factory()->create(['locale' => 'pl']);
    $this->actingAs($pl)->get(route('apartments.index'))->assertSee('Moje mieszkania');
});

test('main screens in English contain no Polish interface words', function () {
    [$en, $apartment, $lease] = ownerWithLease('en');
    $this->actingAs($en);

    $pages = [
        route('dashboard'), route('apartments.index'), route('apartments.create'),
        route('apartments.show', $apartment), route('apartments.edit', $apartment),
        route('apartments.owners', $apartment), route('apartments.history', $apartment),
        route('leases.show', [$apartment, $lease]), route('leases.edit', [$apartment, $lease]),
        route('leases.ledger', [$apartment, $lease]), route('leases.create', $apartment),
        route('bills.index'), route('bills.create'), route('payments.index'),
        route('profile.edit'), route('appearance.edit'),
    ];

    $polishWords = ['Dodaj', 'Zapisz', 'Mieszkani', 'Najem', 'Wpłat', 'Rachunk', 'Rozliczeni', 'Właścicie', 'Opłat', 'Usuń', 'Edytuj', 'Anuluj', 'Pulpit', 'Historia', 'Wyloguj', 'Ustawienia', 'Najemc', 'Kaucj', 'zł', 'wrzesie', 'Wrzesie', 'sierp', 'paździer'];

    foreach ($pages as $url) {
        $html = strip_tags($this->get($url)->assertOk()->getContent());
        $html = str_replace(['Kawalerka Mokotów', 'Jan Kowalski', 'Anna Nowak', 'Polski', 'złoty', config('app.name')], '', $html);

        foreach ($polishWords as $word) {
            expect(str_contains($html, $word))->toBeFalse("\"{$word}\" found on {$url}");
        }
    }
});

test('the language is changed in the appearance settings', function () {
    $user = User::factory()->create(['locale' => 'pl']);
    $this->actingAs($user);

    Livewire::test('pages::settings.appearance')
        ->assertSee('Język / Language')
        ->set('locale', 'en')
        ->assertRedirect(route('appearance.edit'));

    expect($user->fresh()->locale)->toBe('en');

    $this->get(route('dashboard'))->assertSee('Dashboard');

    Livewire::test('pages::settings.appearance')->set('locale', 'xx')->assertHasErrors('locale');
});

test('guests get the language of their browser and keep it after registering', function () {
    $this->get(route('login'), ['Accept-Language' => 'en-GB,en;q=0.9'])->assertSee('Log in to your account');
    $this->get(route('login'), ['Accept-Language' => 'pl-PL,pl;q=0.9'])->assertSee('Zaloguj się na swoje konto');
    $this->get(route('login'), ['Accept-Language' => 'de-DE'])->assertSee('Zaloguj się na swoje konto');

    $this->post(route('register.store'), [
        'name' => 'John Smith', 'email' => 'john@example.com', 'password' => 'password', 'password_confirmation' => 'password',
    ], ['Accept-Language' => 'en']);

    expect(User::where('email', 'john@example.com')->value('locale'))->toBe('en');
});

test('each tenant gets e-mails in their own language, whatever the owner uses', function () {
    Mail::fake();
    [$owner, $apartment, $lease] = ownerWithLease('pl');
    $lease->tenants()->first()->update(['locale' => 'en']);
    $lease->tenants()->create(['first_name' => 'Ewa', 'last_name' => 'Nowak', 'email' => 'ewa@example.com', 'locale' => 'pl', 'is_primary' => false]);

    $this->artisan('tenants:notify');

    Mail::assertSent(TenantDigestMail::class, 2);

    Mail::assertSent(TenantDigestMail::class, function (TenantDigestMail $mail) {
        $html = $mail->render();

        return $mail->locale === 'en'
            && $mail->hasTo('jan@example.com') && ! $mail->hasTo('ewa@example.com')
            && str_contains($html, 'Hello, Jan!')
            && str_contains($html, 'Total to pay: PLN')
            && str_contains($html, 'Rent – September 2026')
            && ! str_contains($html, 'Łącznie');
    });

    Mail::assertSent(TenantDigestMail::class, fn (TenantDigestMail $mail) => $mail->locale === 'pl'
        && $mail->hasTo('ewa@example.com')
        && str_contains($mail->render(), 'Łącznie do zapłaty')
        && str_contains($mail->render(), 'Opłata za mieszkanie – wrzesień 2026'));

    // Owners get a copy of both.
    Mail::assertSent(TenantDigestMail::class, fn (TenantDigestMail $mail) => $mail->hasCc($owner->email) && $mail->locale === 'en');
    Mail::assertSent(TenantDigestMail::class, fn (TenantDigestMail $mail) => $mail->hasCc($owner->email) && $mail->locale === 'pl');
});

test('the English e-mail subject and preview are in English', function () {
    [$owner, $apartment, $lease] = ownerWithLease('pl');
    $lease->tenants()->update(['locale' => 'en']);

    $this->actingAs($owner)
        ->get(route('leases.notification-preview', [$apartment, $lease]))
        ->assertOk()
        ->assertSee('New charges')
        ->assertSee('Kind regards')
        ->assertDontSee('Nowe opłaty');

    $this->get(route('leases.notification-preview', [$apartment, $lease, 'lang' => 'pl']))->assertSee('Nowe opłaty');

    // A real send (into memory) builds the subject in the tenant's language.
    config(['mail.default' => 'array']);
    $this->artisan('tenants:notify');

    $sent = app('mailer')->getSymfonyTransport()->messages();
    expect($sent)->toHaveCount(1)
        ->and($sent->first()->getOriginalMessage()->getSubject())->toBe('Kawalerka Mokotów – PLN'."\u{00A0}".'2,500 to pay');
});

test('the tenant language is chosen in the lease wizard and tenant form', function () {
    [$owner, $apartment, $lease] = ownerWithLease('pl');
    $this->actingAs($owner);

    $tenant = $lease->tenants->first();

    Livewire::test('pages::leases.show', ['apartment' => $apartment, 'lease' => $lease])
        ->call('openTenant', $tenant->id)
        ->assertSet('tenantLocale', 'pl')
        ->set('tenantLocale', 'en')
        ->call('saveTenant')
        ->assertHasNoErrors()
        ->assertSee('EN');

    expect($tenant->fresh()->locale)->toBe('en');
});
