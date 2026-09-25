<?php

use App\Actions\Bills\UploadBills;
use App\Actions\Leases\CreateLease;
use App\Enums\ChargeType;
use App\Models\Activity;
use App\Models\Apartment;
use App\Models\Bill;
use App\Models\LeasePayer;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Livewire\Livewire;

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->owner = User::factory()->create();
    $this->actingAs($this->owner);
    $this->apartment = Apartment::factory()->ownedBy($this->owner)->create();

    $this->lease = app(CreateLease::class)->handle(
        $this->apartment, $this->owner,
        ['starts_on' => now()->startOfMonth()->toDateString(), 'ends_on' => null, 'currency' => 'PLN', 'payment_due_day' => 10,
            'bank_account' => 'PL61109010140000071219812874', 'notes' => 'Klucze u sąsiadki Zofii'],
        [['first_name' => 'Ewelina', 'last_name' => 'Tajemnicza', 'email' => 'ewelina@example.com', 'phone' => '600 700 800']],
        [['type' => ChargeType::Rent, 'name' => null, 'amount' => 250000]],
    );
});

/**
 * Everything stored in the database as one string, the way a thief with a copy would see it.
 */
function rawDatabase(): string
{
    $dump = '';
    foreach (['lease_tenants', 'leases', 'activity_log', 'bank_transactions', 'lease_payers', 'ledger_entries', 'bills'] as $table) {
        $dump .= json_encode(DB::table($table)->get(), JSON_UNESCAPED_UNICODE);
    }

    return $dump;
}

test('tenant details, bank accounts, notes and history are unreadable in the database', function () {
    $this->lease->tenants->first()->update(['phone' => '511 222 333']);

    $raw = rawDatabase();

    foreach (['Ewelina', 'Tajemnicza', 'ewelina@example.com', '600 700 800', '511 222 333', '61109010140000071219812874', 'sąsiadki'] as $secret) {
        expect($raw)->not->toContain($secret);
    }

    // …while the application reads them normally.
    $tenant = $this->lease->fresh()->tenants->first();
    expect($tenant->full_name)->toBe('Ewelina Tajemnicza')
        ->and($tenant->email)->toBe('ewelina@example.com')
        ->and($this->lease->fresh()->bank_account)->toBe('PL61109010140000071219812874');

    $this->get(route('apartments.history', $this->apartment))
        ->assertOk()
        ->assertSee('Dodano najemcę: Ewelina Tajemnicza')
        ->assertSee('511 222 333');
});

test('bank statement details and remembered payer accounts are encrypted', function () {
    $csv = "#Data operacji;#Opis operacji;#Rachunek;#Kategoria;#Kwota;\r\n"
        .now()->toDateString().';"EWELINA TAJEMNICZA, czynsz  PRZELEW ZEWNĘTRZNY PRZYCHODZĄCY  53102000000000000000001234";"PRV";"x";2 500,00 PLN;;'."\r\n";

    Livewire::test('pages::payments.index')
        ->set('statement', UploadedFile::fake()->createWithContent('w.csv', $csv))
        ->call('book');

    $raw = rawDatabase();
    expect($raw)->not->toContain('TAJEMNICZA')
        ->and($raw)->not->toContain('53102000000000000000001234');

    $payer = LeasePayer::sole();
    expect($payer->account)->toBe('PL53102000000000000000001234')
        ->and(LeasePayer::forAccount('PL53102000000000000000001234')->exists())->toBeTrue();
});

test('invoice files are stored encrypted and served decrypted', function () {
    Storage::fake('local');
    Http::fake(['openrouter.ai/*' => Http::response(['choices' => [['message' => ['content' => json_encode(['is_bill' => true, 'apartment_id' => null, 'match_confidence' => 'none', 'match_reason' => '', 'address_on_invoice' => 'Ewelina Tajemnicza, Długa 1', 'category' => 'water', 'supplier' => null, 'invoice_number' => null, 'issued_on' => null, 'period_from' => null, 'period_to' => null, 'due_on' => null, 'currency' => 'PLN', 'amount_to_pay' => 10, 'notes' => null])]]]])]);
    config(['services.openrouter.key' => 'test']);

    $pdf = '%PDF-1.4 Faktura dla: Ewelina Tajemnicza';
    [$bill] = app(UploadBills::class)->handle($this->owner, [UploadedFile::fake()->createWithContent('faktura.pdf', $pdf)]);

    $stored = Storage::disk('local')->get($bill->file_path);
    expect($stored)->not->toContain('Tajemnicza')
        ->and(rawDatabase())->not->toContain('Tajemnicza');

    // The AI still received the real document.
    Http::assertSent(fn ($request) => str_contains($request->body(), base64_encode($pdf)));

    $this->get(route('bills.file', Bill::sole()))
        ->assertOk()
        ->assertHeader('Content-Type', 'application/pdf')
        ->assertSee('Ewelina Tajemnicza', false);
});

test('history can still be filtered by apartment', function () {
    expect(Activity::where('apartment_id', $this->apartment->id)->count())->toBeGreaterThan(0)
        ->and(Activity::where('apartment_id', $this->apartment->id)->where('event', 'created')->where('log_name', 'lease_tenants')->sole()->getProperty('apartment_id'))->toBe($this->apartment->id);
});
