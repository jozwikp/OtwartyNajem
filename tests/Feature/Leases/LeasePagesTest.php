<?php

use App\Actions\Leases\CreateLease;
use App\Enums\ChargeType;
use App\Models\Apartment;
use App\Models\Lease;
use App\Models\LedgerEntry;
use App\Models\User;
use App\Support\Money;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Livewire\Livewire;

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->travelTo(CarbonImmutable::parse('2026-09-24'));
    $this->user = User::factory()->create(['name' => 'Anna Nowak']);
    $this->apartment = Apartment::factory()->ownedBy($this->user)->create();
    $this->actingAs($this->user);
});

function makeLease(array $overrides = []): Lease
{
    return app(CreateLease::class)->handle(
        test()->apartment,
        test()->user,
        ['starts_on' => '2026-09-01', 'ends_on' => null, 'currency' => 'PLN', 'payment_due_day' => 10, 'deposit_amount' => 500000, 'deposit_method' => 'transfer', ...$overrides],
        [['first_name' => 'Jan', 'last_name' => 'Kowalski', 'email' => 'jan@example.com', 'phone' => '600 100 200']],
        [['type' => ChargeType::Rent, 'name' => null, 'amount' => 250000]],
    );
}

test('the lease tab shows an empty state without a lease', function () {
    $this->get(route('leases.current', $this->apartment))
        ->assertOk()
        ->assertSee('To mieszkanie nie ma jeszcze najmu');

    $this->get(route('leases.ledger.current', $this->apartment))->assertOk();
});

test('a lease can be created with the wizard', function () {
    Livewire::test('pages::leases.create', ['apartment' => $this->apartment])
        ->set('tenants.0.first_name', 'Jan')
        ->set('tenants.0.last_name', 'Kowalski')
        ->set('tenants.0.email', 'Jan@Example.com')
        ->call('next')
        ->assertHasNoErrors()
        ->set('form.starts_on', '2026-08-15')
        ->set('form.bank_account', '61 1090 1014 0000 0712 1981 2874')
        ->call('next')
        ->assertHasNoErrors()
        ->set('form.has_deposit', true)
        ->set('form.deposit_amount', '5 000')
        ->set('form.deposit_method', 'cash')
        ->call('next')
        ->assertHasNoErrors()
        ->set('rent', '2500')
        ->set('adminFee', '480,50')
        ->call('addOtherCharge')
        ->set('otherCharges.0.name', 'Garaż')
        ->set('otherCharges.0.amount', '200')
        ->call('next')
        ->assertHasNoErrors()
        ->assertSet('step', 5)
        ->call('save')
        ->assertRedirect();

    $lease = Lease::sole();

    expect($lease->bank_account)->toBe('PL61109010140000071219812874')
        ->and($lease->deposit_amount)->toBe(500000)
        ->and($lease->deposit_method->value)->toBe('cash')
        ->and($lease->tenants->sole()->email)->toBe('jan@example.com')
        ->and($lease->recurringCharges)->toHaveCount(3)
        // August (prorated) and September for each of the three charges
        ->and(LedgerEntry::count())->toBe(6);
});

test('the wizard rejects an invalid bank account', function () {
    Livewire::test('pages::leases.create', ['apartment' => $this->apartment])
        ->set('step', 2)
        ->set('form.starts_on', '2026-09-01')
        ->set('form.bank_account', '61 1090 1014 0000 0712 1981 2875')
        ->call('next')
        ->assertHasErrors('form.bank_account');
});

test('the lease page shows tenants, charges and deposit', function () {
    $lease = makeLease();

    $this->get(route('leases.current', $this->apartment))
        ->assertOk()
        ->assertSee('Jan Kowalski')
        ->assertSee('Opłata za mieszkanie')
        ->assertSee('Jeszcze nie zwrócona')
        ->assertSee('Do zapłaty');

    $this->get(route('leases.show', [$this->apartment, $lease]))->assertOk();
    $this->get(route('leases.edit', [$this->apartment, $lease]))->assertOk();
});

test('rent can be raised from a future month', function () {
    $lease = makeLease();
    $rent = $lease->recurringCharges->first();

    Livewire::test('pages::leases.show', ['apartment' => $this->apartment, 'lease' => $lease])
        ->call('openChangeAmount', $rent->id)
        ->assertSet('validFrom', '2026-10-01')
        ->set('amount', '2700')
        ->call('changeAmount')
        ->assertHasNoErrors()
        ->assertSee('od 1 października 2026');

    expect($rent->rates()->count())->toBe(2);
});

test('a lease can be ended and the deposit returned partially with a note', function () {
    $lease = makeLease();

    Livewire::test('pages::leases.show', ['apartment' => $this->apartment, 'lease' => $lease])
        ->call('openEndLease')
        ->set('endsOn', '2026-09-15')
        ->call('endLease')
        ->assertHasNoErrors()
        ->call('openDepositReturn')
        ->set('returnedAmount', '4500')
        ->call('saveDepositReturn')
        ->assertHasErrors('returnNotes')
        ->set('returnNotes', 'Naprawa drzwi')
        ->call('saveDepositReturn')
        ->assertHasNoErrors();

    $lease->refresh();

    expect($lease->terminated_on->toDateString())->toBe('2026-09-15')
        ->and($lease->deposit_returned_amount)->toBe(450000)
        ->and($lease->ledgerEntries()->sole()->amount)->toBe(125000);
});

test('payments can be recorded and deleted on the ledger page', function () {
    $lease = makeLease();

    Livewire::test('pages::leases.ledger', ['apartment' => $this->apartment, 'lease' => $lease])
        ->assertSee('Do zapłaty: '.Money::format(250000, 'PLN'))
        ->call('openPayment')
        ->assertSet('paymentAmount', '2500')
        ->call('savePayment')
        ->assertHasNoErrors()
        ->assertSee('Wszystko rozliczone');

    $payment = $lease->ledgerEntries()->where('kind', 'payment')->sole();

    Livewire::test('pages::leases.ledger', ['apartment' => $this->apartment, 'lease' => $lease])
        ->call('deletePayment', $payment->id);

    expect($lease->balance())->toBe(250000);
});

test('other users cannot see leases, ledgers or invoices', function () {
    Storage::fake('local');
    $lease = makeLease();
    $bill = $lease->bills()->create([
        'status' => 'approved',
        'category' => 'water', 'issued_on' => '2026-09-01', 'period_from' => '2026-08-01', 'period_to' => '2026-08-31',
        'currency' => 'PLN', 'total_amount' => 100, 'tenant_amount' => 100, 'due_on' => '2026-09-15',
        'file_path' => 'bills/x.pdf', 'file_name' => 'x.pdf',
    ]);

    $this->actingAs(User::factory()->create());

    $this->get(route('leases.current', $this->apartment))->assertForbidden();
    $this->get(route('leases.ledger', [$this->apartment, $lease]))->assertForbidden();
    $bill->forceFill(['apartment_id' => $this->apartment->id])->save();

    $this->get(route('bills.file', $bill))->assertForbidden();
    $this->get(route('bills.edit', $bill))->assertForbidden();
});

test('a lease cannot be opened through another apartment', function () {
    $lease = makeLease();
    $otherApartment = Apartment::factory()->ownedBy($this->user)->create();

    $this->get(route('leases.show', [$otherApartment, $lease]))->assertNotFound();
});

test('lease changes appear in the apartment history', function () {
    $lease = makeLease();
    $lease->tenants->first()->update(['phone' => '700 000 000']);

    $this->get(route('apartments.history', $this->apartment))
        ->assertOk()
        ->assertSee('Dodano najem od 01.09.2026')
        ->assertSee('Dodano najemcę: Jan Kowalski')
        ->assertSee('Zmieniono dane najemcy: Jan Kowalski')
        ->assertSee('700 000 000')
        ->assertSee('Naliczono: Opłata za mieszkanie – wrzesień 2026');

    $this->get(route('dashboard'))->assertOk()->assertSee('Dodano najem');
});
