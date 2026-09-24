<?php

use App\Actions\Bills\UpdateBill;
use App\Actions\Leases\CreateLease;
use App\Actions\Leases\RecordPayment;
use App\Enums\BillStatus;
use App\Enums\ChargeType;
use App\Enums\PaymentMethod;
use App\Mail\TenantDigestMail;
use App\Models\Apartment;
use App\Models\Bill;
use App\Models\Lease;
use App\Models\User;
use App\Support\Money;
use Carbon\CarbonImmutable;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;
use Livewire\Livewire;
use Spatie\Activitylog\Models\Activity;

uses(RefreshDatabase::class);

beforeEach(function () {
    Mail::fake();
    $this->travelTo(CarbonImmutable::parse('2026-09-24 10:00', 'Europe/Warsaw'));

    $this->owner = User::factory()->create(['name' => 'Anna Nowak', 'email' => 'anna@example.com']);
    $this->apartment = Apartment::factory()->ownedBy($this->owner)->create(['label' => 'Kawalerka Mokotów']);
    $this->actingAs($this->owner);
});

function leaseStarting(string $startsOn, array $overrides = []): Lease
{
    return app(CreateLease::class)->handle(
        test()->apartment, test()->owner,
        ['starts_on' => $startsOn, 'ends_on' => null, 'currency' => 'PLN', 'payment_due_day' => 10, 'bank_account' => 'PL61109010140000071219812874', ...$overrides],
        [['first_name' => 'Jan', 'last_name' => 'Kowalski', 'email' => 'jan@example.com', 'phone' => null]],
        [['type' => ChargeType::Rent, 'name' => null, 'amount' => 250000]],
    );
}

function approvedBill(Lease $lease, int $amount = 31240): Bill
{
    $bill = Bill::create([
        'status' => BillStatus::Review, 'file_path' => 'bills/x.pdf', 'file_name' => 'x.pdf', 'created_by' => test()->owner->id,
    ]);

    app(UpdateBill::class)->handle($bill, test()->owner, [
        'apartment_id' => $lease->apartment_id, 'category' => 'electricity', 'supplier' => 'Tauron',
        'issued_on' => '2026-09-20', 'period_from' => '2026-08-01', 'period_to' => '2026-08-31', 'due_on' => '2026-10-04',
        'currency' => 'PLN', 'total_amount' => $amount, 'tenant_amount' => $amount,
    ], approve: true);

    return $bill->fresh();
}

test('one daily summary goes out with new charges, balance and bank details', function () {
    $lease = leaseStarting('2026-09-01');
    approvedBill($lease);

    $this->artisan('tenants:notify')->assertSuccessful();

    Mail::assertSent(TenantDigestMail::class, 1);
    Mail::assertSent(TenantDigestMail::class, function (TenantDigestMail $mail) {
        $html = $mail->render();

        return $mail->hasTo('jan@example.com')
            && $mail->hasReplyTo('anna@example.com')
            && $mail->hasSubject('Kawalerka Mokotów – do zapłaty '.Money::format(281240, 'PLN'))
            && str_contains($html, 'Opłata za mieszkanie – wrzesień 2026')
            && str_contains($html, 'Rachunek: Prąd (Tauron)')
            && str_contains($html, '61 1090 1014 0000 0712 1981 2874')
            && $mail->attachments === [];
    });

    // Nothing new – nothing sent.
    $this->artisan('tenants:notify');
    Mail::assertSent(TenantDigestMail::class, 1);

    $activity = Activity::where('event', 'tenant_notified')->sole();
    expect($activity->getProperty('recipients'))->toBe(['jan@example.com'])
        ->and($activity->getProperty('balance'))->toBe(281240)
        ->and($lease->fresh()->last_notified_at)->not->toBeNull();

    $this->get(route('apartments.history', $this->apartment))->assertSee('Wysłano podsumowanie do najemcy (jan@example.com)');
});

test('past months of a newly entered lease are not announced', function () {
    leaseStarting('2026-06-01');

    $this->artisan('tenants:notify');

    Mail::assertSent(TenantDigestMail::class, function (TenantDigestMail $mail) {
        return $mail->digest->newCharges->count() === 1
            && str_contains($mail->digest->newCharges->first()->description, 'wrzesień');
    });
});

test('changed amounts are reported as corrections', function () {
    $lease = leaseStarting('2026-09-01');
    $bill = approvedBill($lease, 31240);
    $this->artisan('tenants:notify');

    app(UpdateBill::class)->handle($bill, $this->owner, ['tenant_amount' => 30000, 'total_amount' => 31240]);
    $this->artisan('tenants:notify');

    Mail::assertSent(TenantDigestMail::class, 2);
    Mail::assertSent(TenantDigestMail::class, fn (TenantDigestMail $mail) => $mail->digest->changedCharges->count() === 1
        && $mail->digest->changedCharges->first()->notified_amount === 31240
        && str_contains($mail->render(), 'Zmienione kwoty'));
});

test('payments alone do not trigger a mail but show up in the next one', function () {
    $lease = leaseStarting('2026-09-01');
    $this->artisan('tenants:notify');

    app(RecordPayment::class)->handle($lease, $this->owner, 250000, CarbonImmutable::parse('2026-09-24'), PaymentMethod::Transfer);
    $this->artisan('tenants:notify');
    Mail::assertSent(TenantDigestMail::class, 1);

    approvedBill($lease, 10000);
    $this->artisan('tenants:notify');

    Mail::assertSent(TenantDigestMail::class, fn (TenantDigestMail $mail) => $mail->digest->payments->sum('amount') === 250000
        && str_contains($mail->render(), 'Otrzymane wpłaty'));
});

test('nothing is sent when notifications are off or the tenant has no e-mail', function () {
    $lease = leaseStarting('2026-09-01', ['notify_tenants' => false]);
    $this->artisan('tenants:notify');
    Mail::assertNothingSent();

    $lease->update(['notify_tenants' => true]);
    $lease->tenants()->update(['email' => null]);
    approvedBill($lease);
    $this->artisan('tenants:notify');
    Mail::assertNothingSent();
});

test('turning notifications on does not send the backlog', function () {
    $lease = leaseStarting('2026-09-01', ['notify_tenants' => false]);
    approvedBill($lease);

    Livewire::test('pages::leases.show', ['apartment' => $this->apartment, 'lease' => $lease])
        ->assertSee('wyłączone')
        ->call('toggleNotifications')
        ->assertSee('Nie ma nic nowego do wysłania');

    $this->artisan('tenants:notify');
    Mail::assertNothingSent();
});

test('the lease page shows what goes out today and a preview', function () {
    $lease = leaseStarting('2026-09-01');

    $this->get(route('leases.show', [$this->apartment, $lease]))
        ->assertOk()
        ->assertSee('Dziś po 16:00 wyślemy 1 nową pozycję.');

    $this->get(route('leases.notification-preview', [$this->apartment, $lease]))
        ->assertOk()
        ->assertSee('Nowe opłaty')
        ->assertSee('Łącznie do zapłaty');
});

test('the summary is scheduled once a day at 16:00 Warsaw time', function () {
    $event = collect(app(Schedule::class)->events())
        ->first(fn ($e) => str_contains($e->command, 'tenants:notify'));

    expect($event->expression)->toBe('0 16 * * *')
        ->and($event->timezone)->toBe('Europe/Warsaw');
});
