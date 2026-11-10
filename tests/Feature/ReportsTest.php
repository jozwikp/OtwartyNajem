<?php

use App\Actions\Leases\CreateLease;
use App\Actions\Leases\RecordPayment;
use App\Enums\ChargeType;
use App\Enums\PaymentMethod;
use App\Models\Apartment;
use App\Models\User;
use App\Support\Money;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;

uses(RefreshDatabase::class);

test('guests are redirected to login', function () {
    $this->get(route('reports.index'))->assertRedirect(route('login'));
});

test('the report sums rent as income and approved bills as expense, by the month they belong to', function () {
    $this->travelTo(CarbonImmutable::parse('2026-09-24 12:00'));

    $user = User::factory()->create();
    $this->actingAs($user);

    $apartment = Apartment::factory()->ownedBy($user)->create();

    app(CreateLease::class)->handle(
        $apartment, $user,
        ['starts_on' => '2026-09-01', 'ends_on' => null, 'currency' => 'PLN', 'payment_due_day' => 10],
        [['first_name' => 'Jan', 'last_name' => 'Kowalski', 'email' => null, 'phone' => null]],
        [
            ['type' => ChargeType::Rent, 'name' => null, 'amount' => 250000],
            ['type' => ChargeType::AdminFee, 'name' => null, 'amount' => 60000],
        ],
    );

    // Approved today, but billed for August -> accrual lands in August, cash (approximated) in September.
    Livewire::test('pages::bills.create', ['apartmentId' => (string) $apartment->id])
        ->set('category', 'gas')
        ->set('supplier', 'PGNiG')
        ->call('next')
        ->assertHasNoErrors()
        ->set('totalAmount', '145,80')
        ->set('periodFrom', '2026-08-01')
        ->set('periodTo', '2026-08-31')
        ->call('next')
        ->assertHasNoErrors()
        ->call('save')
        ->assertRedirect(route('bills.index'));

    $this->get(route('reports.index'))->assertOk();

    $component = Livewire::test('pages::reports.index');
    $months = $component->instance()->monthsOf('PLN');
    $august = $months->first(fn ($row) => $row['month']->format('Y-m') === '2026-08');
    $september = $months->first(fn ($row) => $row['month']->format('Y-m') === '2026-09');

    // Income is only the rent (2500 zł), not the 600 zł admin fee.
    expect($september['accrual_income'])->toBe(250000)
        ->and($september['accrual_expense'])->toBe(0)
        ->and($august['accrual_expense'])->toBe(14580)
        ->and($september['cash_expense'])->toBe(14580)
        ->and($august['cash_expense'])->toBe(0)
        // No payment recorded yet, so cash income is still zero everywhere.
        ->and($september['cash_income'])->toBe(0);

    $component->assertSee(Money::format(250000, 'PLN'))
        ->assertSee(Money::format(14580, 'PLN'));
});

test('cash income is counted when the payment was actually booked, not when the rent was charged', function () {
    $this->travelTo(CarbonImmutable::parse('2026-09-24 12:00'));

    $user = User::factory()->create();
    $this->actingAs($user);

    $apartment = Apartment::factory()->ownedBy($user)->create();

    $lease = app(CreateLease::class)->handle(
        $apartment, $user,
        ['starts_on' => '2026-09-01', 'ends_on' => null, 'currency' => 'PLN', 'payment_due_day' => 10],
        [['first_name' => 'Jan', 'last_name' => 'Kowalski', 'email' => null, 'phone' => null]],
        [['type' => ChargeType::Rent, 'name' => null, 'amount' => 250000]],
    );

    $this->travelTo(CarbonImmutable::parse('2026-10-05 12:00'));
    app(RecordPayment::class)->handle($lease, $user, 250000, CarbonImmutable::parse('2026-10-05'), PaymentMethod::Transfer);

    $component = Livewire::test('pages::reports.index', ['year' => 2026]);
    $months = $component->instance()->monthsOf('PLN');
    $september = $months->first(fn ($row) => $row['month']->format('Y-m') === '2026-09');
    $october = $months->first(fn ($row) => $row['month']->format('Y-m') === '2026-10');

    expect($september['accrual_income'])->toBe(250000)
        ->and($september['cash_income'])->toBe(0)
        ->and($october['accrual_income'])->toBe(0)
        ->and($october['cash_income'])->toBe(250000);
});

test('owners only see their own apartments in the report', function () {
    $this->travelTo(CarbonImmutable::parse('2026-09-24 12:00'));

    $user = User::factory()->create();
    $other = User::factory()->create();
    $this->actingAs($user);

    $apartment = Apartment::factory()->ownedBy($other)->create();
    app(CreateLease::class)->handle(
        $apartment, $other,
        ['starts_on' => '2026-09-01', 'ends_on' => null, 'currency' => 'PLN', 'payment_due_day' => 10],
        [['first_name' => 'Ola', 'last_name' => 'Nowak', 'email' => null, 'phone' => null]],
        [['type' => ChargeType::Rent, 'name' => null, 'amount' => 999999]],
    );

    $component = Livewire::test('pages::reports.index');

    expect($component->instance()->currencies())->toBe([]);
});
