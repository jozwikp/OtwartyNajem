<?php

use App\Actions\Leases\AccrueRecurringCharges;
use App\Actions\Leases\ChangeChargeAmount;
use App\Actions\Leases\CreateLease;
use App\Actions\Leases\EndLease;
use App\Actions\Leases\RecordPayment;
use App\Enums\ChargeType;
use App\Enums\PaymentMethod;
use App\Models\Apartment;
use App\Models\LedgerEntry;
use App\Models\User;
use App\Support\LeaseStatement;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Validation\ValidationException;

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->travelTo(CarbonImmutable::parse('2026-09-24'));
    $this->user = User::factory()->create();
    $this->apartment = Apartment::factory()->ownedBy($this->user)->create();
    $this->actingAs($this->user);
});

function createLease(array $overrides = [], ?array $charges = null)
{
    return app(CreateLease::class)->handle(
        test()->apartment,
        test()->user,
        ['starts_on' => '2026-07-15', 'ends_on' => null, 'currency' => 'PLN', 'payment_due_day' => 10, ...$overrides],
        [['first_name' => 'Jan', 'last_name' => 'Kowalski', 'email' => 'jan@example.com', 'phone' => null]],
        $charges ?? [
            ['type' => ChargeType::Rent, 'name' => null, 'amount' => 310000],
            ['type' => ChargeType::AdminFee, 'name' => null, 'amount' => 62000],
        ],
    );
}

test('fixed charges are accrued from the start month up to the current month', function () {
    $lease = createLease();

    $rent = $lease->ledgerEntries()->whereHasMorph('source', '*')->get()
        ->filter(fn ($e) => $e->description !== '' && str_starts_with($e->description, 'Opłata za mieszkanie'))
        ->sortBy('period')->values();

    expect($rent)->toHaveCount(3)
        // July: 15-31 = 17 of 31 days
        ->and($rent[0]->amount)->toBe((int) round(310000 * 17 / 31))
        ->and($rent[0]->booked_on->toDateString())->toBe('2026-07-15')
        ->and($rent[0]->due_on->toDateString())->toBe('2026-07-15')
        ->and($rent[0]->description)->toContain('17 z 31 dni')
        ->and($rent[1]->amount)->toBe(310000)
        ->and($rent[1]->due_on->toDateString())->toBe('2026-08-10')
        ->and($rent[2]->period->toDateString())->toBe('2026-09-01');

    expect(LedgerEntry::count())->toBe(6);
});

test('accrual is idempotent and continues in the next month', function () {
    $lease = createLease();

    app(AccrueRecurringCharges::class)->handle($lease);
    expect(LedgerEntry::count())->toBe(6);

    $this->travelTo(CarbonImmutable::parse('2026-10-01'));
    $this->artisan('leases:accrue')->assertSuccessful();

    expect(LedgerEntry::count())->toBe(8);
});

test('a future lease is not charged yet', function () {
    createLease(['starts_on' => '2026-11-01']);

    expect(LedgerEntry::count())->toBe(0);
});

test('amount changes apply from the chosen month and never backwards', function () {
    $lease = createLease();
    $rent = $lease->recurringCharges()->where('type', ChargeType::Rent)->first();

    expect(fn () => app(ChangeChargeAmount::class)->handle($rent, $this->user, 330000, CarbonImmutable::parse('2026-09-01')))
        ->toThrow(ValidationException::class);

    app(ChangeChargeAmount::class)->handle($rent, $this->user, 330000, CarbonImmutable::parse('2026-11-01'));

    $this->travelTo(CarbonImmutable::parse('2026-11-05'));
    app(AccrueRecurringCharges::class)->handle($lease->fresh());

    $amounts = $rent->ledgerEntries()->orderBy('period')->pluck('amount', 'period')->mapWithKeys(fn ($a, $p) => [substr($p, 0, 7) => $a]);

    expect($amounts['2026-10'])->toBe(310000)
        ->and($amounts['2026-11'])->toBe(330000);
});

test('an amount of zero stops the charge', function () {
    $lease = createLease();
    $admin = $lease->recurringCharges()->where('type', ChargeType::AdminFee)->first();

    app(ChangeChargeAmount::class)->handle($admin, $this->user, 0, CarbonImmutable::parse('2026-10-01'));
    $this->travelTo(CarbonImmutable::parse('2026-10-20'));
    app(AccrueRecurringCharges::class)->handle($lease->fresh());

    expect($admin->ledgerEntries()->count())->toBe(3);
});

test('ending a lease prorates the last month and removes later charges', function () {
    $lease = createLease(['starts_on' => '2026-07-01'], [['type' => ChargeType::Rent, 'name' => null, 'amount' => 300000]]);

    app(EndLease::class)->handle($lease, CarbonImmutable::parse('2026-08-20'));

    $entries = $lease->ledgerEntries()->orderBy('period')->get();

    expect($entries)->toHaveCount(2)
        ->and($entries[0]->amount)->toBe(300000)
        ->and($entries[1]->amount)->toBe((int) round(300000 * 20 / 31));
});

test('leases cannot overlap', function () {
    createLease(['starts_on' => '2026-01-01', 'ends_on' => '2026-12-31']);

    expect(fn () => createLease(['starts_on' => '2026-12-01']))->toThrow(ValidationException::class);

    createLease(['starts_on' => '2027-01-01']);
    expect($this->apartment->leases()->count())->toBe(2);
});

test('payments cover the oldest charges first', function () {
    $lease = createLease(['starts_on' => '2026-07-01'], [['type' => ChargeType::Rent, 'name' => null, 'amount' => 100000]]);

    app(RecordPayment::class)->handle($lease, $this->user, 150000, CarbonImmutable::parse('2026-08-12'), PaymentMethod::Transfer);

    $statement = new LeaseStatement($lease);
    [$july, $august, $september] = $statement->charges->all();

    expect($statement->balance())->toBe(150000)
        ->and($statement->statusOf($july))->toBe('paid')
        ->and($statement->remainingFor($august))->toBe(50000)
        ->and($statement->statusOf($august))->toBe('overdue')
        ->and($statement->statusOf($september))->toBe('overdue')
        ->and($statement->overdueAmount())->toBe(150000);
});

test('ledger entries must use the lease currency', function () {
    $lease = createLease(['currency' => 'EUR']);

    expect($lease->ledgerEntries()->pluck('currency')->unique()->all())->toBe(['EUR']);

    $entry = new LedgerEntry(['kind' => 'payment', 'currency' => 'PLN', 'amount' => 100, 'booked_on' => '2026-09-01', 'description' => 'x']);
    $entry->lease()->associate($lease);

    expect(fn () => $entry->save())->toThrow(DomainException::class);
});
