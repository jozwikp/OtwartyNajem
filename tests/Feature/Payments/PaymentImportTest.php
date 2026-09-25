<?php

use App\Actions\Leases\CreateLease;
use App\Actions\Leases\RecordPayment;
use App\Enums\BankTransactionStatus;
use App\Enums\ChargeType;
use App\Enums\PaymentMethod;
use App\Models\Apartment;
use App\Models\BankImport;
use App\Models\BankTransaction;
use App\Models\Lease;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Livewire\Livewire;

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->travelTo(CarbonImmutable::parse('2026-09-24 12:00'));
    $this->owner = User::factory()->create();
    $this->actingAs($this->owner);

    $this->ewa = tenantLease('Kawalerka Mokotów', 'Ewa', 'Matkowska', 250000);
    $this->jan = tenantLease('Dom w Gdańsku', 'Jan', 'Wiśniewski', 400000);
});

function tenantLease(string $label, string $first, string $last, int $rent, ?User $owner = null): Lease
{
    $owner ??= test()->owner;
    $apartment = Apartment::factory()->ownedBy($owner)->create(['label' => $label]);

    return app(CreateLease::class)->handle(
        $apartment, $owner,
        ['starts_on' => '2026-09-01', 'ends_on' => null, 'currency' => 'PLN', 'payment_due_day' => 10],
        [['first_name' => $first, 'last_name' => $last, 'email' => null, 'phone' => null]],
        [['type' => ChargeType::Rent, 'name' => null, 'amount' => $rent]],
    );
}

/**
 * @param  list<array{0: string, 1: string, 2: string}>  $rows  date, description, amount
 */
function uploadStatement(array $rows, string $name = 'wyciag.csv')
{
    $csv = "#Data operacji;#Opis operacji;#Rachunek;#Kategoria;#Kwota;\r\n";
    foreach ($rows as [$date, $description, $amount]) {
        $csv .= $date.';"'.$description.'";"PRV 1111 ... 2222";"Bez kategorii";'.$amount.' PLN;;'."\r\n";
    }

    return Livewire::test('pages::payments.index')
        ->set('statement', UploadedFile::fake()->createWithContent($name, $csv));
}

test('tenant transfers are recognised, spending and refunds are not', function () {
    uploadStatement([
        ['2026-09-10', 'EWA MATKOWSKA UL. DŁUGA 5 00-001 WARSZAWA, czynsz wrzesien  PRZELEW ZEWNĘTRZNY PRZYCHODZĄCY  53102000000000000000001234', '2 500,00'],
        ['2026-09-09', 'BIEDRONKA  ZAKUP PRZY UŻYCIU KARTY W KRAJU', '-54,20'],
        ['2026-09-08', 'ANSWEAR.COM SPÓŁKA AKCYJNA, ZWROT Z TYT. 260914  PRZELEW ZEWNĘTRZNY PRZYCHODZĄCY', '189,99'],
    ])->assertHasNoErrors()->assertSee('Rozpoznane wpłaty od najemców (1)');

    // Outgoing spending is never stored.
    expect(BankTransaction::count())->toBe(2);

    $rent = BankTransaction::where('amount', 250000)->sole();
    expect($rent->status)->toBe(BankTransactionStatus::Suggested)
        ->and($rent->lease_id)->toBe($this->ewa->id)
        ->and($rent->confidence)->toBe('high')
        ->and($rent->match_reason)->toContain('Imię i nazwisko najemcy');

    expect(BankTransaction::where('amount', 18999)->sole()->status)->toBe(BankTransactionStatus::Ignored);
});

test('someone else paying with the tenant name in the title is recognised', function () {
    uploadStatement([
        ['2026-09-11', 'BARBARA NOWAKOWSKA UL. POLNA 3 78-600 WALCZ, za czynsz Jan Wisniewski  PRZELEW ZEWNĘTRZNY PRZYCHODZĄCY  12345678901234567890123456', '4 000,00'],
    ]);

    $transaction = BankTransaction::sole();
    expect($transaction->status)->toBe(BankTransactionStatus::Suggested)
        ->and($transaction->lease_id)->toBe($this->jan->id);
});

test('a surname variant waits for the owner to decide', function () {
    uploadStatement([
        ['2026-09-11', 'ADAM MATKOWSKI, przelew  PRZELEW ZEWNĘTRZNY PRZYCHODZĄCY', '700,00'],
    ])->assertSee('Do Twojej decyzji (1)');

    $transaction = BankTransaction::sole();
    expect($transaction->status)->toBe(BankTransactionStatus::Review)
        ->and($transaction->lease_id)->toBe($this->ewa->id);
});

test('an unknown transfer with a rent title waits for a decision without a tenant', function () {
    uploadStatement([['2026-09-12', 'KTOS INNY, oplata za mieszkanie wrzesien  PRZELEW ZEWNĘTRZNY PRZYCHODZĄCY', '500,00']]);

    $transaction = BankTransaction::sole();
    expect($transaction->status)->toBe(BankTransactionStatus::Review)
        ->and($transaction->lease_id)->toBeNull();
});

test('ambiguous names are never booked automatically', function () {
    tenantLease('Pokój na Pradze', 'Ewa', 'Matkowska', 150000);

    uploadStatement([['2026-09-10', 'EWA MATKOWSKA, czynsz  PRZELEW ZEWNĘTRZNY PRZYCHODZĄCY', '999,00']]);

    expect(BankTransaction::sole()->status)->toBe(BankTransactionStatus::Review);
});

test('booking creates payments and remembers the payer account for next time', function () {
    $component = uploadStatement([
        ['2026-09-10', 'EWA MATKOWSKA, czynsz  PRZELEW ZEWNĘTRZNY PRZYCHODZĄCY  53102000000000000000001234', '2 500,00'],
        ['2026-09-11', 'MAMA EWY, przelew  PRZELEW ZEWNĘTRZNY PRZYCHODZĄCY  99102000000000000000009999', '300,00'],
    ]);

    $mother = BankTransaction::where('amount', 30000)->sole();
    expect($mother->status)->toBe(BankTransactionStatus::Ignored);

    $component->call('assign', $mother->id)
        ->set("leaseFor.{$mother->id}", (string) $this->ewa->id)
        ->assertSet("selected.{$mother->id}", true)
        ->call('book')
        ->assertHasNoErrors();

    expect($this->ewa->balance())->toBe(250000 - 250000 - 30000)
        ->and(BankTransaction::where('status', 'booked')->count())->toBe(2)
        ->and($this->ewa->payers()->pluck('account')->all())->toEqualCanonicalizing(['PL53102000000000000000001234', 'PL99102000000000000000009999']);

    // Next month the mother's account alone is enough.
    uploadStatement([['2026-10-11', 'MAMA EWY, przelew  PRZELEW ZEWNĘTRZNY PRZYCHODZĄCY  99102000000000000000009999', '300,00']], 'pazdziernik.csv');

    $next = BankTransaction::latest('id')->first();
    expect($next->status)->toBe(BankTransactionStatus::Suggested)
        ->and($next->lease_id)->toBe($this->ewa->id)
        ->and($next->match_reason)->toContain('Konto, z którego już wcześniej płacono');
});

test('the same statement uploaded twice does not double the payments', function () {
    $rows = [
        ['2026-09-10', 'EWA MATKOWSKA, czynsz  PRZELEW ZEWNĘTRZNY PRZYCHODZĄCY', '1 000,00'],
        ['2026-09-10', 'EWA MATKOWSKA, czynsz  PRZELEW ZEWNĘTRZNY PRZYCHODZĄCY', '1 000,00'],
    ];

    uploadStatement($rows)->call('book');
    uploadStatement($rows);

    expect(BankImport::latest('id')->first()->duplicates)->toBe(2)
        ->and(BankTransaction::count())->toBe(2)
        ->and($this->ewa->ledgerEntries()->where('kind', 'payment')->count())->toBe(2);
});

test('a manually recorded payment is flagged as a possible duplicate', function () {
    app(RecordPayment::class)->handle($this->ewa, $this->owner, 250000, CarbonImmutable::parse('2026-09-09'), PaymentMethod::Transfer);

    uploadStatement([['2026-09-10', 'EWA MATKOWSKA, czynsz  PRZELEW ZEWNĘTRZNY PRZYCHODZĄCY', '2 500,00']]);

    $transaction = BankTransaction::sole();
    expect($transaction->status)->toBe(BankTransactionStatus::Review)
        ->and($transaction->match_reason)->toContain('Podobna wpłata jest już zapisana ręcznie');
});

test('deleting a booked payment returns the transfer for a decision', function () {
    uploadStatement([['2026-09-10', 'EWA MATKOWSKA, czynsz  PRZELEW ZEWNĘTRZNY PRZYCHODZĄCY', '2 500,00']])->call('book');

    $payment = $this->ewa->ledgerEntries()->where('kind', 'payment')->sole();
    expect($payment->description)->toBe('Wpłata – przelew z wyciągu bankowego');

    $payment->delete();

    expect(BankTransaction::sole()->status)->toBe(BankTransactionStatus::Review);
});

test('skipped transfers are not booked', function () {
    $component = uploadStatement([['2026-09-10', 'EWA MATKOWSKA, czynsz  PRZELEW ZEWNĘTRZNY PRZYCHODZĄCY', '2 500,00']]);
    $transaction = BankTransaction::sole();

    $component->call('skip', $transaction->id)->call('book');

    expect($transaction->fresh()->status)->toBe(BankTransactionStatus::Ignored)
        ->and($this->ewa->ledgerEntries()->where('kind', 'payment')->count())->toBe(0);
});

test('transfers cannot be booked to someone else\'s lease', function () {
    $foreignLease = tenantLease('Cudze', 'Olga', 'Obca', 100000, User::factory()->create());
    $component = uploadStatement([['2026-09-10', 'OLGA OBCA, czynsz  PRZELEW ZEWNĘTRZNY PRZYCHODZĄCY', '1 000,00']]);
    $transaction = BankTransaction::sole();

    expect($transaction->lease_id)->toBeNull();

    $component->set("selected.{$transaction->id}", true)
        ->set("leaseFor.{$transaction->id}", (string) $foreignLease->id)
        ->call('book');

    expect($foreignLease->ledgerEntries()->where('kind', 'payment')->count())->toBe(0);
});

test('a payment can be entered by hand with the amount due suggested', function () {
    Livewire::test('pages::payments.index')
        ->set('leaseId', (string) $this->jan->id)
        ->assertSet('amount', '4000')
        ->set('amount', '3 500')
        ->set('method', 'cash')
        ->call('saveManual')
        ->assertHasNoErrors()
        ->assertSee('Jan Wiśniewski');

    $payment = $this->jan->ledgerEntries()->where('kind', 'payment')->sole();
    expect($payment->amount)->toBe(350000)
        ->and($payment->payment_method)->toBe(PaymentMethod::Cash);
});

test('the payments page needs a login', function () {
    auth()->logout();

    $this->get(route('payments.index'))->assertRedirect(route('login'));
});
