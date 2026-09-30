<?php

use App\Models\Apartment;
use App\Models\ApartmentInvitation;
use App\Models\User;
use App\Notifications\NewUserRegisteredNotification;
use Illuminate\Auth\Notifications\VerifyEmail;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Notifications\AnonymousNotifiable;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\URL;
use Livewire\Livewire;

uses(RefreshDatabase::class);

function registerAccount(array $overrides = []): void
{
    test()->post(route('register.store'), [
        'name' => 'Anna Nowak',
        'email' => 'anna@example.com',
        'password' => 'password',
        'password_confirmation' => 'password',
        ...$overrides,
    ])->assertSessionHasNoErrors();
}

test('a new account gets a confirmation link and cannot use the app before confirming', function () {
    Notification::fake();

    registerAccount();

    $user = User::firstWhere('email', 'anna@example.com');
    expect($user->hasVerifiedEmail())->toBeFalse();
    Notification::assertSentTo($user, VerifyEmail::class, fn ($notification, $channels, $notifiable, $locale) => $locale === 'pl');

    $this->get(route('dashboard'))->assertRedirect(route('verification.notice'));
    $this->get(route('apartments.index'))->assertRedirect(route('verification.notice'));
    $this->get(route('verification.notice'))->assertOk()->assertSee('anna@example.com');
});

test('the confirmation link verifies the account', function () {
    $user = User::factory()->unverified()->create();

    $url = URL::temporarySignedRoute('verification.verify', now()->addHour(), [
        'id' => $user->id,
        'hash' => sha1($user->email),
    ]);

    $this->actingAs($user)->get($url)->assertRedirect(route('dashboard', absolute: false).'?verified=1');

    expect($user->fresh()->hasVerifiedEmail())->toBeTrue();
    $this->get(route('dashboard'))->assertOk();
});

test('the confirmation link can be sent again', function () {
    Notification::fake();
    $user = User::factory()->unverified()->create();

    $this->actingAs($user)->post(route('verification.send'))
        ->assertSessionHas('status', 'verification-link-sent');

    Notification::assertSentTo($user, VerifyEmail::class);
});

test('changing the e-mail address requires confirming the new one', function () {
    Notification::fake();
    $user = User::factory()->create();

    Livewire::actingAs($user)->test('pages::settings.profile')
        ->set('name', $user->name)
        ->set('email', 'new@example.com')
        ->call('updateProfileInformation')
        ->assertHasNoErrors();

    expect($user->fresh()->hasVerifiedEmail())->toBeFalse();
    Notification::assertSentTo($user, VerifyEmail::class);
    $this->get(route('dashboard'))->assertRedirect(route('verification.notice'));
});

test('an unconfirmed account cannot accept an invitation', function () {
    $owner = User::factory()->create();
    $apartment = Apartment::factory()->ownedBy($owner)->create();
    ApartmentInvitation::factory()->withPlainToken($token = 'secret-token')->for($apartment)->create();
    $user = User::factory()->unverified()->create(['email' => 'anna@example.com']);

    Livewire::actingAs($user)->test('pages::invitations.show', ['token' => $token])
        ->assertRedirect(route('verification.notice'));

    expect(session('url.intended'))->toBe(route('invitations.show', $token))
        ->and($apartment->isOwnedBy($user))->toBeFalse();
});

test('the administrator is told about every new account', function () {
    Notification::fake();
    config(['app.admin_email' => 'admin@example.com']);

    registerAccount(['name' => 'Jan Kowalski', 'email' => 'jan@example.com']);

    Notification::assertSentOnDemand(
        NewUserRegisteredNotification::class,
        function (NewUserRegisteredNotification $notification, array $channels, AnonymousNotifiable $notifiable) {
            $mail = $notification->toMail($notifiable);

            return $notifiable->routes['mail'] === 'admin@example.com'
                && $notification->locale === 'pl'
                && $mail->subject === 'Nowe konto: Jan Kowalski'
                && in_array('E-mail: jan@example.com', $mail->introLines, true);
        },
    );
});

test('no administrator alert without an administrator address', function () {
    Notification::fake();
    config(['app.admin_email' => null]);

    registerAccount();

    Notification::assertNothingSentTo(new AnonymousNotifiable);
    Notification::assertSentOnDemandTimes(NewUserRegisteredNotification::class, 0);
});
