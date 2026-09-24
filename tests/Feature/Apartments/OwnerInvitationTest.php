<?php

use App\Models\Apartment;
use App\Models\ApartmentInvitation;
use App\Models\User;
use App\Notifications\ApartmentInvitationNotification;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Notifications\AnonymousNotifiable;
use Illuminate\Support\Facades\Notification;
use Livewire\Livewire;
use Spatie\Activitylog\Models\Activity;

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->owner = User::factory()->create();
    $this->apartment = Apartment::factory()->ownedBy($this->owner)->create();
});

test('an owner can invite a co-owner by e-mail', function () {
    Notification::fake();
    $this->actingAs($this->owner);

    Livewire::test('pages::apartments.owners', ['apartment' => $this->apartment])
        ->set('email', 'Jan@Example.com ')
        ->call('invite')
        ->assertHasNoErrors();

    $invitation = ApartmentInvitation::sole();
    expect($invitation->email)->toBe('jan@example.com')
        ->and($invitation->invited_by)->toBe($this->owner->id)
        ->and($invitation->isPending())->toBeTrue();

    Notification::assertSentTo(new AnonymousNotifiable, ApartmentInvitationNotification::class,
        fn ($notification, $channels, $notifiable) => $notifiable->routes['mail'] === 'jan@example.com');

    expect(Activity::forSubject($this->apartment)->forEvent('owner_invited')->sole()->causer->is($this->owner))->toBeTrue();
});

test('existing owners and pending invitations cannot be invited again', function () {
    Notification::fake();
    $this->actingAs($this->owner);

    Livewire::test('pages::apartments.owners', ['apartment' => $this->apartment])
        ->set('email', $this->owner->email)
        ->call('invite')
        ->assertHasErrors('email')
        ->set('email', 'jan@example.com')
        ->call('invite')
        ->assertHasNoErrors()
        ->set('email', 'jan@example.com')
        ->call('invite')
        ->assertHasErrors('email');

    expect(ApartmentInvitation::count())->toBe(1);
});

test('the invitation link lets a guest sign up and come back to accept', function () {
    ApartmentInvitation::factory()->withPlainToken('secret-token')
        ->for($this->apartment)
        ->create(['email' => 'nowy@example.com', 'invited_by' => $this->owner->id]);

    $this->get(route('invitations.show', 'secret-token'))
        ->assertOk()
        ->assertSee($this->apartment->label)
        ->assertSee('Załóż konto');

    $this->post(route('register.store'), [
        'name' => 'Nowy Właściciel',
        'email' => 'nowy@example.com',
        'password' => 'password',
        'password_confirmation' => 'password',
    ])->assertRedirect(route('invitations.show', 'secret-token'));
});

test('a logged in user can accept an invitation', function () {
    $invitee = User::factory()->create();
    $invitation = ApartmentInvitation::factory()->withPlainToken('secret-token')
        ->for($this->apartment)
        ->create(['email' => $invitee->email, 'invited_by' => $this->owner->id]);

    $this->actingAs($invitee);

    Livewire::test('pages::invitations.show', ['token' => 'secret-token'])
        ->call('accept')
        ->assertRedirect(route('apartments.show', $this->apartment));

    expect($this->apartment->isOwnedBy($invitee))->toBeTrue()
        ->and($invitation->refresh()->accepted_by)->toBe($invitee->id)
        ->and(Activity::forSubject($this->apartment)->forEvent('owner_joined')->sole()->causer->is($invitee))->toBeTrue();

    $this->get(route('apartments.edit', $this->apartment))->assertOk();
});

test('an invitation can be declined', function () {
    $invitation = ApartmentInvitation::factory()->withPlainToken('secret-token')->for($this->apartment)->create();
    $this->actingAs(User::factory()->create());

    Livewire::test('pages::invitations.show', ['token' => 'secret-token'])->call('decline');

    expect($invitation->refresh()->isDeclined())->toBeTrue()
        ->and($this->apartment->owners()->count())->toBe(1);
});

test('expired invitations cannot be accepted', function () {
    ApartmentInvitation::factory()->withPlainToken('secret-token')->for($this->apartment)->expired()->create();
    $this->actingAs(User::factory()->create());

    Livewire::test('pages::invitations.show', ['token' => 'secret-token'])
        ->assertSee('Zaproszenie wygasło')
        ->call('accept')
        ->assertNotFound();
});

test('resending an invitation replaces the link', function () {
    Notification::fake();
    $invitation = ApartmentInvitation::factory()->withPlainToken('old-token')->for($this->apartment)->create();
    $this->actingAs($this->owner);

    Livewire::test('pages::apartments.owners', ['apartment' => $this->apartment])
        ->call('resend', $invitation->id);

    expect(ApartmentInvitation::findByPlainToken('old-token'))->toBeNull();
    Notification::assertSentTimes(ApartmentInvitationNotification::class, 1);
});

test('an invitation can be cancelled', function () {
    $invitation = ApartmentInvitation::factory()->for($this->apartment)->create();
    $this->actingAs($this->owner);

    Livewire::test('pages::apartments.owners', ['apartment' => $this->apartment])
        ->call('cancel', $invitation->id);

    expect(ApartmentInvitation::count())->toBe(0)
        ->and(Activity::forSubject($this->apartment)->forEvent('invitation_cancelled')->exists())->toBeTrue();
});

test('co-owners can remove each other but never the last owner', function () {
    $coOwner = User::factory()->create();
    $this->apartment->owners()->attach($coOwner);
    $this->actingAs($coOwner);

    Livewire::test('pages::apartments.owners', ['apartment' => $this->apartment])
        ->call('confirmRemoval', $this->owner->id)
        ->call('remove')
        ->assertHasNoErrors();

    expect($this->apartment->isOwnedBy($this->owner))->toBeFalse();

    Livewire::test('pages::apartments.owners', ['apartment' => $this->apartment])
        ->call('confirmRemoval', $coOwner->id)
        ->call('remove')
        ->assertHasErrors('owner');

    expect($this->apartment->isOwnedBy($coOwner))->toBeTrue()
        ->and(Activity::forSubject($this->apartment)->forEvent('owner_removed')->sole()->causer->is($coOwner))->toBeTrue();
});

test('other users cannot manage owners', function () {
    $this->actingAs(User::factory()->create());

    $this->get(route('apartments.owners', $this->apartment))->assertForbidden();
});
