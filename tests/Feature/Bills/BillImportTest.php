<?php

use App\Actions\Leases\CreateLease;
use App\Enums\BillStatus;
use App\Enums\ChargeType;
use App\Models\Apartment;
use App\Models\Bill;
use App\Models\User;
use App\Services\BillReader;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Livewire\Livewire;
use Spatie\Activitylog\Models\Activity;

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->travelTo(CarbonImmutable::parse('2026-09-24'));
    Storage::fake('local');
    config(['services.openrouter.key' => 'test-key', 'services.openrouter.model' => 'openai/gpt-5-nano']);

    $this->user = User::factory()->create();
    $this->apartment = Apartment::factory()->ownedBy($this->user)->create([
        'label' => 'Kawalerka Mokotów', 'street' => 'Puławska 12A', 'unit_number' => '5', 'postal_code' => '02-512', 'city' => 'Warszawa',
    ]);
    $this->otherApartment = Apartment::factory()->ownedBy($this->user)->create(['label' => 'Dom w Gdańsku']);
    $this->lease = app(CreateLease::class)->handle(
        $this->apartment, $this->user,
        ['starts_on' => '2026-07-01', 'ends_on' => null, 'currency' => 'PLN', 'payment_due_day' => 10],
        [['first_name' => 'Jan', 'last_name' => 'Kowalski', 'email' => null, 'phone' => null]],
        [['type' => ChargeType::Rent, 'name' => null, 'amount' => 250000]],
    );

    $this->actingAs($this->user);
});

function aiAnswer(array $overrides = []): array
{
    $answer = [
        'is_bill' => true,
        'apartment_id' => test()->apartment->id,
        'match_confidence' => 'high',
        'match_reason' => 'Ulica, numer, lokal i kod pocztowy się zgadzają.',
        'address_on_invoice' => 'ul. Pulawska 12 A m. 5, 02-512 Warszawa',
        'category' => 'electricity',
        'supplier' => 'Tauron',
        'invoice_number' => 'FV/2026/08/1',
        'issued_on' => '2026-09-05',
        'period_from' => '2026-08-01',
        'period_to' => '2026-08-31',
        'due_on' => '2026-09-19',
        'currency' => 'PLN',
        'amount_to_pay' => 312.4,
        'notes' => null,
        ...$overrides,
    ];

    return [
        'model' => 'openai/gpt-5-nano',
        'choices' => [['message' => ['content' => json_encode($answer)]]],
        'usage' => ['cost' => 0.0004],
    ];
}

function fakeAiAnswer(array $overrides = []): void
{
    Http::fake(['openrouter.ai/*' => Http::response(aiAnswer($overrides))]);
}

function uploadInvoices(int $count = 1)
{
    $files = [];
    for ($i = 1; $i <= $count; $i++) {
        $files[] = UploadedFile::fake()->create("faktura-{$i}.pdf", 100, 'application/pdf');
    }

    return Livewire::test('pages::bills.index')->set('uploads', $files);
}

test('uploaded invoices are read by AI and matched to the apartment', function () {
    fakeAiAnswer();

    uploadInvoices(2)->assertHasNoErrors()->assertSee('Do sprawdzenia (2)');

    $bill = Bill::first();

    expect(Bill::count())->toBe(2)
        ->and($bill->status)->toBe(BillStatus::Review)
        ->and($bill->apartment_id)->toBe($this->apartment->id)
        ->and($bill->lease_id)->toBe($this->lease->id)
        ->and($bill->total_amount)->toBe(31240)
        ->and($bill->tenant_amount)->toBe(31240)
        ->and($bill->period_from->toDateString())->toBe('2026-08-01')
        ->and($bill->aiValue('address_on_invoice'))->toContain('Pulawska')
        // Nothing is charged before approval
        ->and($bill->ledgerEntry)->toBeNull();

    Storage::disk('local')->assertExists($bill->file_path);

    Http::assertSent(function (Request $request) {
        $body = $request->data();

        return $request->hasHeader('Authorization', 'Bearer test-key')
            && $body['model'] === 'openai/gpt-5-nano'
            && $body['response_format']['type'] === 'json_schema'
            && str_contains($body['messages'][0]['content'], 'Puławska 12A')
            && $body['messages'][1]['content'][1]['type'] === 'file';
    });
});

test('approving a bill charges the tenant', function () {
    fakeAiAnswer();
    $component = uploadInvoices();
    $bill = Bill::sole();

    $component->call('approve', $bill->id)->assertHasNoErrors();

    $bill->refresh();
    expect($bill->status)->toBe(BillStatus::Approved)
        ->and($bill->approved_by)->toBe($this->user->id)
        ->and($bill->ledgerEntry->amount)->toBe(31240)
        ->and($bill->ledgerEntry->lease_id)->toBe($this->lease->id)
        ->and($bill->ledgerEntry->due_on->toDateString())->toBe('2026-09-19');

    expect(Activity::where('log_name', 'bills')->where('event', 'ai_read')->exists())->toBeTrue();
});

test('a bill without a recognised apartment must be corrected before approval', function () {
    fakeAiAnswer(['apartment_id' => null, 'match_confidence' => 'none']);
    $component = uploadInvoices()->assertSee('Nie rozpoznano mieszkania');
    $bill = Bill::sole();

    $component->call('approve', $bill->id);
    expect($bill->fresh()->status)->toBe(BillStatus::Review);

    $component->call('openCorrection', $bill->id)
        ->set('correctApartmentId', (string) $this->apartment->id)
        ->set('correctAmount', '200')
        ->call('saveCorrection', true)
        ->assertHasNoErrors();

    $bill->refresh();
    expect($bill->status)->toBe(BillStatus::Approved)
        ->and($bill->tenant_amount)->toBe(20000)
        ->and($bill->total_amount)->toBe(31240)
        ->and($bill->ledgerEntry->amount)->toBe(20000);
});

test('an apartment id the user does not own is ignored', function () {
    $foreign = Apartment::factory()->ownedBy(User::factory()->create())->create();
    fakeAiAnswer(['apartment_id' => $foreign->id]);

    uploadInvoices();

    expect(Bill::sole()->apartment_id)->toBeNull();
});

test('a bill for a period without a lease is approved without charging anyone', function () {
    fakeAiAnswer(['apartment_id' => null]);
    $component = uploadInvoices();
    $bill = Bill::sole();

    $component->call('openCorrection', $bill->id)
        ->set('correctApartmentId', (string) $this->otherApartment->id)
        ->set('correctAmount', '312,40')
        ->call('saveCorrection', true);

    $bill->refresh();
    expect($bill->status)->toBe(BillStatus::Approved)
        ->and($bill->lease_id)->toBeNull()
        ->and($bill->ledgerEntry)->toBeNull();
});

test('failed reading can be retried or filled in by hand', function () {
    Http::fake(['openrouter.ai/*' => Http::sequence()
        ->push(['error' => ['message' => 'Rate limit']], 429)
        ->push(aiAnswer())]);

    $component = uploadInvoices()->assertSee('Nie udało się odczytać dokumentu');
    $bill = Bill::sole();

    expect($bill->status)->toBe(BillStatus::Failed)
        ->and($bill->ai_error)->toContain('Rate limit');

    $component->call('retry', $bill->id);

    expect($bill->fresh()->status)->toBe(BillStatus::Review);
});

test('approved bills can be edited and the charge follows', function () {
    fakeAiAnswer();
    uploadInvoices()->call('approve', Bill::sole()->id);
    $bill = Bill::sole();

    Livewire::test('pages::bills.edit', ['bill' => $bill])
        ->assertSet('tenantAmount', '312,40')
        ->set('tenantAmount', '300')
        ->set('supplier', 'Tauron Sprzedaż')
        ->call('save')
        ->assertHasNoErrors()
        ->assertRedirect(route('bills.index'));

    expect($bill->fresh()->ledgerEntry->amount)->toBe(30000);

    // Moving it to an apartment without a lease removes the charge.
    Livewire::test('pages::bills.edit', ['bill' => $bill->fresh()])
        ->set('apartmentId', (string) $this->otherApartment->id)
        ->call('save')
        ->assertHasNoErrors();

    expect($bill->fresh()->ledgerEntry)->toBeNull()
        ->and($this->lease->balance())->toBe(3 * 250000);
});

test('a bill in a different currency than the lease cannot be approved', function () {
    fakeAiAnswer(['currency' => 'EUR']);
    $component = uploadInvoices()->assertSee('Faktura jest w EUR');

    $component->call('approve', Bill::sole()->id);

    expect(Bill::sole()->status)->toBe(BillStatus::Review);
});

test('a document that is not a bill is flagged', function () {
    fakeAiAnswer(['is_bill' => false, 'apartment_id' => null, 'notes' => 'To umowa najmu.']);

    uploadInvoices()->assertSee('nie wygląda na fakturę');

    expect(Bill::sole()->status)->toBe(BillStatus::Failed);
});

test('only pdf and images up to 10 MB are accepted', function () {
    Livewire::test('pages::bills.index')
        ->set('uploads', [UploadedFile::fake()->create('umowa.docx', 100)])
        ->assertHasErrors('uploads.0');

    expect(Bill::count())->toBe(0);
});

test('bills are private to the owners of the apartment', function () {
    fakeAiAnswer();
    uploadInvoices();
    $bill = Bill::sole();

    $this->actingAs(User::factory()->create());

    $this->get(route('bills.edit', $bill))->assertForbidden();
    $this->get(route('bills.file', $bill))->assertForbidden();
    Livewire::test('pages::bills.index')->assertDontSee($bill->file_name);

    // A co-owner sees and manages it.
    $coOwner = User::factory()->create();
    $this->apartment->owners()->attach($coOwner);
    $this->actingAs($coOwner)->get(route('bills.edit', $bill))->assertOk();
});

test('the reader asks for strict structured output with the apartment list', function () {
    $schema = app(BillReader::class)->schema();

    expect($schema['additionalProperties'])->toBeFalse()
        ->and($schema['required'])->toEqualCanonicalizing(array_keys($schema['properties']));
});
