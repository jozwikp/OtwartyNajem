<?php

use App\Models\Activity;
use App\Models\Apartment;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;

uses(RefreshDatabase::class);

test('guests are redirected to login', function () {
    $this->get(route('apartments.index'))->assertRedirect(route('login'));
});

test('owners see only their own apartments', function () {
    $user = User::factory()->create();
    $mine = Apartment::factory()->ownedBy($user)->create(['label' => 'Moje M2']);
    Apartment::factory()->ownedBy(User::factory()->create())->create(['label' => 'Cudze M3']);

    $this->actingAs($user)
        ->get(route('apartments.index'))
        ->assertOk()
        ->assertSee('Moje M2')
        ->assertDontSee('Cudze M3');

    $this->get(route('apartments.show', $mine))->assertOk();
});

test('users cannot open apartments they do not own', function (string $route) {
    $apartment = Apartment::factory()->ownedBy(User::factory()->create())->create();

    $this->actingAs(User::factory()->create())
        ->get(route($route, $apartment))
        ->assertForbidden();
})->with(['apartments.show', 'apartments.edit', 'apartments.owners', 'apartments.history']);

test('an apartment can be added in three steps', function () {
    $user = User::factory()->create();
    $this->actingAs($user);

    Livewire::test('pages::apartments.create')
        ->set('form.street', 'Puławska 12A')
        ->set('form.unit_number', '5')
        ->set('form.postal_code', '02512')
        ->set('form.city', 'Warszawa')
        ->call('next')
        ->assertHasNoErrors()
        ->assertSet('step', 2)
        ->assertSet('form.postal_code', '02-512')
        ->assertSet('form.label', 'Puławska 12A/5, Warszawa')
        ->set('form.area', '48,5')
        ->call('next')
        ->assertHasNoErrors()
        ->assertSet('step', 3)
        ->call('save')
        ->assertRedirect();

    $apartment = Apartment::sole();

    expect($apartment)
        ->street->toBe('Puławska 12A')
        ->unit_number->toBe('5')
        ->postal_code->toBe('02-512')
        ->country_code->toBe('PL')
        ->area->toBe('48.50')
        ->created_by->toBe($user->id)
        ->and($apartment->isOwnedBy($user))->toBeTrue();
});

test('the unit number is optional for a whole building', function () {
    $this->actingAs(User::factory()->create());

    Livewire::test('pages::apartments.create')
        ->set('form.street', 'Długa 1')
        ->set('form.postal_code', '80-001')
        ->set('form.city', 'Gdańsk')
        ->call('next')
        ->assertHasNoErrors()
        ->set('form.area', '320')
        ->call('next')
        ->call('save');

    expect(Apartment::sole()->unit_number)->toBeNull();
});

test('the first step validates the address', function () {
    $this->actingAs(User::factory()->create());

    Livewire::test('pages::apartments.create')
        ->set('form.postal_code', '123')
        ->call('next')
        ->assertHasErrors(['form.street', 'form.city', 'form.postal_code'])
        ->assertSet('step', 1);
});

test('foreign postal codes are not forced into the Polish format', function () {
    $this->actingAs(User::factory()->create());

    Livewire::test('pages::apartments.create')
        ->set('form.street', 'Karl-Marx-Allee 1')
        ->set('form.postal_code', '10178')
        ->set('form.city', 'Berlin')
        ->set('form.country_code', 'DE')
        ->call('next')
        ->assertHasNoErrors()
        ->assertSet('form.postal_code', '10178');
});

test('owners can edit the apartment and the change is logged', function () {
    $user = User::factory()->create();
    $apartment = Apartment::factory()->ownedBy($user)->create(['area' => 40]);
    $this->actingAs($user);

    Livewire::test('pages::apartments.edit', ['apartment' => $apartment])
        ->set('form.area', '42,5')
        ->call('save')
        ->assertHasNoErrors();

    expect($apartment->refresh()->area)->toBe('42.50');

    $activity = Activity::forSubject($apartment)->forEvent('updated')->sole();

    expect($activity->causer->is($user))->toBeTrue()
        ->and($activity->attribute_changes['old']['area'])->toBe('40.00')
        ->and($activity->attribute_changes['attributes']['area'])->toBe('42.50')
        ->and($activity->getProperty('ip'))->not->toBeNull();
});

test('owners can delete the apartment', function () {
    $user = User::factory()->create();
    $apartment = Apartment::factory()->ownedBy($user)->create();
    $this->actingAs($user);

    Livewire::test('pages::apartments.edit', ['apartment' => $apartment])
        ->call('delete')
        ->assertRedirect(route('apartments.index'));

    expect($apartment->refresh()->trashed())->toBeTrue()
        ->and(Activity::forSubject($apartment)->forEvent('deleted')->exists())->toBeTrue();
});

test('the history page shows who changed what', function () {
    $user = User::factory()->create(['name' => 'Anna Nowak']);
    $this->actingAs($user);
    $apartment = Apartment::factory()->ownedBy($user)->create(['city' => 'Kraków']);
    $apartment->update(['city' => 'Wrocław']);

    $this->get(route('apartments.history', $apartment))
        ->assertOk()
        ->assertSee('Anna Nowak')
        ->assertSee('Zmieniono dane mieszkania')
        ->assertSee('Kraków')
        ->assertSee('Wrocław');
});

test('the sidebar lists the user\'s apartments', function () {
    $user = User::factory()->create();
    Apartment::factory()->ownedBy($user)->create(['label' => 'Kawalerka Mokotów']);
    Apartment::factory()->ownedBy(User::factory()->create())->create(['label' => 'Cudze M3']);

    $this->actingAs($user)
        ->get(route('dashboard'))
        ->assertOk()
        ->assertSee('Kawalerka Mokotów')
        ->assertDontSee('Cudze M3');
});
