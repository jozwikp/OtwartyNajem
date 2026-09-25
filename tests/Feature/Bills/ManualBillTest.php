<?php

use App\Actions\Leases\CreateLease;
use App\Enums\BillStatus;
use App\Enums\ChargeType;
use App\Models\Apartment;
use App\Models\Bill;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->travelTo(CarbonImmutable::parse('2026-09-24 12:00'));
    $this->owner = User::factory()->create();
    $this->actingAs($this->owner);
    $this->apartment = Apartment::factory()->ownedBy($this->owner)->create(['label' => 'Kawalerka Mokotów']);
    $this->emptyApartment = Apartment::factory()->ownedBy($this->owner)->create(['label' => 'Puste']);

    $this->lease = app(CreateLease::class)->handle(
        $this->apartment, $this->owner,
        ['starts_on' => '2026-07-01', 'ends_on' => null, 'currency' => 'PLN', 'payment_due_day' => 10],
        [['first_name' => 'Jan', 'last_name' => 'Kowalski', 'email' => null, 'phone' => null]],
        [['type' => ChargeType::Rent, 'name' => null, 'amount' => 250000]],
    );
});

test('a bill can be added by hand in three steps and is charged right away', function () {
    Livewire::test('pages::bills.create', ['apartmentId' => (string) $this->apartment->id])
        ->set('category', 'gas')
        ->set('supplier', 'PGNiG')
        ->call('next')
        ->assertHasNoErrors()
        ->set('totalAmount', '145,80')
        ->set('periodFrom', '2026-08-01')
        ->set('periodTo', '2026-08-31')
        ->call('next')
        ->assertHasNoErrors()
        ->assertSee('Obciążymy: Jan Kowalski')
        ->call('save')
        ->assertRedirect(route('bills.index'));

    $bill = Bill::sole();

    expect($bill->status)->toBe(BillStatus::Approved)
        ->and($bill->hasFile())->toBeFalse()
        ->and($bill->total_amount)->toBe(14580)
        ->and($bill->lease_id)->toBe($this->lease->id)
        ->and($bill->approved_by)->toBe($this->owner->id)
        ->and($bill->ledgerEntry->amount)->toBe(14580);

    $this->get(route('bills.edit', $bill))->assertOk()->assertSee('Rachunek dodany ręcznie');
    $this->get(route('bills.file', $bill))->assertNotFound();
    $this->get(route('leases.ledger', [$this->apartment, $this->lease]))->assertOk()->assertSee('Rachunek: Gaz (PGNiG)');
});

test('the tenant can be charged only part of a hand-entered bill', function () {
    Livewire::test('pages::bills.create')
        ->set('apartmentId', (string) $this->apartment->id)
        ->set('category', 'water')
        ->call('next')
        ->set('totalAmount', '300')
        ->set('partial', true)
        ->set('tenantAmount', '350')
        ->call('next')
        ->assertHasErrors('tenantAmount')
        ->set('tenantAmount', '120')
        ->call('next')
        ->call('save');

    expect(Bill::sole()->ledgerEntry->amount)->toBe(12000);
});

test('without a lease in the period the bill is saved without a charge', function () {
    Livewire::test('pages::bills.create', ['apartmentId' => (string) $this->emptyApartment->id])
        ->set('category', 'electricity')
        ->call('next')
        ->set('totalAmount', '99')
        ->call('next')
        ->assertSee('bez obciążania najemcy')
        ->call('save');

    expect(Bill::sole()->ledgerEntry)->toBeNull();
});

test('steps are validated and only own apartments can be chosen', function () {
    $foreign = Apartment::factory()->ownedBy(User::factory()->create())->create();

    Livewire::test('pages::bills.create')
        ->call('next')
        ->assertHasErrors(['apartmentId', 'category'])
        ->set('apartmentId', (string) $foreign->id)
        ->set('category', 'gas')
        ->call('next')
        ->assertHasErrors('apartmentId')
        ->assertSet('step', 1);

    // A foreign apartment in the link is ignored.
    Livewire::test('pages::bills.create', ['apartmentId' => (string) $foreign->id])->assertSet('apartmentId', '');
});

test('approved bills are grouped by month and paginated by 20', function () {
    foreach (range(1, 25) as $i) {
        $month = CarbonImmutable::parse('2026-01-01')->addMonths(intdiv($i - 1, 3));
        $bill = Bill::create(['status' => BillStatus::Approved, 'created_by' => $this->owner->id, 'category' => 'water', 'supplier' => "Dostawca {$i}", 'currency' => 'PLN', 'total_amount' => 1000, 'tenant_amount' => 1000]);
        $bill->forceFill(['apartment_id' => $this->apartment->id, 'period_from' => $month, 'period_to' => $month->endOfMonth()->startOfDay(), 'issued_on' => $month, 'due_on' => $month])->saveQuietly();
    }

    Livewire::test('pages::bills.index')
        ->assertSee('Dostawca 25')        // newest month first
        ->assertSee('Wrzesień 2026', false)
        ->assertSee('1 rachunek')
        ->assertDontSee('Dostawca 5 ')
        ->assertSee('Wyświetlanie')
        ->call('nextPage', 'strona')
        ->assertSee('Dostawca 1')
        ->assertSee('Styczeń 2026', false)
        ->assertDontSee('Dostawca 25');
});
