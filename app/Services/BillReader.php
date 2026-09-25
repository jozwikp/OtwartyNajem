<?php

namespace App\Services;

use App\Enums\BillCategory;
use App\Models\Apartment;
use App\Support\EncryptedFiles;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\RequestException;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Http;
use JsonException;
use RuntimeException;

/**
 * Reads a utility invoice (PDF or photo) with an AI model via OpenRouter and matches it
 * to one of the user's apartments, even when the address is written differently.
 */
class BillReader
{
    /**
     * @param  Collection<int, Apartment>  $apartments
     * @return array<string, mixed> the structured result (see schema())
     */
    public function read(string $path, string $mime, string $fileName, Collection $apartments): array
    {
        $config = config('services.openrouter');

        if (blank($config['key'])) {
            throw new RuntimeException(__('Brak klucza OPENROUTER_API_KEY w konfiguracji.'));
        }

        $payload = [
            'model' => $config['model'],
            'messages' => [
                ['role' => 'system', 'content' => $this->instructions($apartments)],
                ['role' => 'user', 'content' => [
                    ['type' => 'text', 'text' => 'Odczytaj ten dokument i dopasuj go do mieszkania. Nazwa pliku: '.$fileName],
                    $this->filePart($path, $mime, $fileName),
                ]],
            ],
            'response_format' => [
                'type' => 'json_schema',
                'json_schema' => ['name' => 'utility_bill', 'strict' => true, 'schema' => $this->schema()],
            ],
        ];

        try {
            $response = Http::withToken($config['key'])
                ->withHeaders(['X-Title' => config('app.name')])
                ->timeout($config['timeout'])
                ->post(rtrim($config['url'], '/').'/chat/completions', $payload)
                ->throw();
        } catch (ConnectionException $e) {
            throw new RuntimeException(__('Nie udało się połączyć z usługą AI. Spróbuj ponownie za chwilę.'), previous: $e);
        } catch (RequestException $e) {
            $message = $e->response->json('error.message') ?? $e->getMessage();

            throw new RuntimeException(__('Usługa AI zwróciła błąd: :message', ['message' => $message]), previous: $e);
        }

        $content = $response->json('choices.0.message.content');

        if (! is_string($content) || $content === '') {
            throw new RuntimeException(__('Usługa AI nie zwróciła odpowiedzi.'));
        }

        try {
            $result = json_decode($content, true, flags: JSON_THROW_ON_ERROR);
        } catch (JsonException $e) {
            throw new RuntimeException(__('Nie udało się zrozumieć odpowiedzi usługi AI.'), previous: $e);
        }

        $result['_usage'] = $response->json('usage.cost');
        $result['_model'] = $response->json('model') ?? $config['model'];

        return $result;
    }

    /**
     * @return array<string, mixed>
     */
    protected function filePart(string $path, string $mime, string $fileName): array
    {
        $data = 'data:'.$mime.';base64,'.base64_encode(EncryptedFiles::get($path));

        return $mime === 'application/pdf'
            ? ['type' => 'file', 'file' => ['filename' => $fileName, 'file_data' => $data]]
            : ['type' => 'image_url', 'image_url' => ['url' => $data]];
    }

    /**
     * @param  Collection<int, Apartment>  $apartments
     */
    protected function instructions(Collection $apartments): string
    {
        $list = $apartments->map(fn (Apartment $apartment) => [
            'id' => $apartment->id,
            'name' => $apartment->label,
            'street_and_building' => $apartment->street,
            'unit_number' => $apartment->unit_number,
            'postal_code' => $apartment->postal_code,
            'city' => $apartment->city,
            'country' => $apartment->country_code,
        ])->values()->toJson(JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);

        $categories = collect(BillCategory::cases())->map(fn ($c) => $c->value.' = '.$c->label())->join(', ');

        return <<<PROMPT
        Jesteś asystentem właściciela mieszkań na wynajem. Dostajesz jeden dokument: fakturę lub rachunek za media (PDF albo zdjęcie).
        Twoje zadania:
        1. Odczytaj dane z dokumentu.
        2. Dopasuj dokument do jednego z mieszkań użytkownika z listy poniżej.

        MIESZKANIA UŻYTKOWNIKA (JSON):
        {$list}

        JAK DOPASOWAĆ ADRES
        - Na fakturach bywa kilka adresów: adres nabywcy/płatnika (korespondencyjny – często to adres domowy właściciela!) oraz adres punktu poboru / miejsca dostarczania / lokalu (np. "Adres punktu poboru", "Miejsce dostarczania", "Adres PPE", "Adres lokalu", "Obiekt"). Dopasowuj przede wszystkim po adresie punktu poboru/lokalu. Adresu nabywcy użyj tylko, gdy nie ma innego.
        - Adresy są zapisywane różnie. Traktuj jako takie same m.in.:
          * przedrostki "ul.", "al.", "aleja", "os.", "pl.", "plac" – ignoruj;
          * brak polskich znaków, wielkość liter, skróty imion w nazwach ulic ("J. Chełmońskiego" = "Józefa Chełmońskiego" = "Chelmonskiego");
          * numer budynku z literą: "12A" = "12 A" = "12a" = "12/A";
          * numer lokalu: "/5", "m. 5", "m5", "lok. 5", "lokal 5", "mieszk. 5", "apt 5" to ten sam lokal 5;
          * kod pocztowy "02-512" = "02512"; kod pocztowy i miasto są mocnym potwierdzeniem.
        - Jeśli mieszkanie z listy nie ma numeru lokalu (cały budynek), wystarczy zgodność ulicy, numeru budynku i miasta.
        - Jeśli w tym samym budynku jest kilka mieszkań z listy, numer lokalu musi się zgadzać. Gdy na dokumencie brak numeru lokalu, a kandydatów jest kilku – zwróć apartment_id = null.
        - Jeśli żadne mieszkanie nie pasuje sensownie, zwróć apartment_id = null. Nie zgaduj.
        - match_confidence: "high" – ulica, numer i kod/miasto się zgadzają; "medium" – drobne rozbieżności (np. brak kodu); "low" – dopasowanie niepewne; "none" – brak dopasowania.
        - match_reason: jedno krótkie zdanie po polsku, dlaczego wybrałeś to mieszkanie (albo dlaczego żadne).

        JAK ODCZYTAĆ DANE
        - amount_to_pay: końcowa kwota brutto do zapłaty za ten dokument ("Do zapłaty", "Razem do zapłaty", "Kwota do zapłaty"). Jeśli jest rozliczenie z nadpłatą, weź kwotę końcową do zapłaty. Liczba z kropką dziesiętną, np. 312.40. Jeśli dokument to korekta lub nadpłata (kwota ujemna albo 0 do zapłaty) – podaj tę wartość i opisz w notes.
        - currency: kod ISO waluty, np. PLN, EUR.
        - Daty w formacie YYYY-MM-DD. issued_on – data wystawienia; due_on – termin płatności; period_from/period_to – okres rozliczeniowy (za jaki okres jest rachunek). Jeśli podany jest tylko miesiąc, użyj pierwszego i ostatniego dnia tego miesiąca. Brak informacji = null.
        - category – jedna z wartości: {$categories}. Energia elektryczna → electricity; gaz → gas; woda i ścieki → water; ciepło, centralne ogrzewanie → heating; odpady/śmieci → waste; internet, telewizja → internet; inne → other.
        - address_on_invoice: tylko ten jeden adres, którego użyłeś do dopasowania (np. adres punktu poboru), bez etykiet typu "Nabywca:".
        - supplier: krótka nazwa sprzedawcy, np. "Tauron", "PGNiG", "MPWiK".
        - invoice_number: sam numer faktury/dokumentu, bez słów typu "Faktura VAT nr".
        - is_bill: false, jeśli dokument w ogóle nie jest fakturą ani rachunkiem.
        - notes: krótko po polsku o czymś nietypowym (prognoza, faktura zaliczkowa, korekta, kilka faktur w jednym pliku, nieczytelny skan). W przeciwnym razie null.
        PROMPT;
    }

    /**
     * JSON schema of the answer (strict structured output).
     *
     * @return array<string, mixed>
     */
    public function schema(): array
    {
        $nullableString = ['type' => ['string', 'null']];

        return [
            'type' => 'object',
            'additionalProperties' => false,
            'required' => [
                'is_bill', 'apartment_id', 'match_confidence', 'match_reason', 'address_on_invoice',
                'category', 'supplier', 'invoice_number', 'issued_on', 'period_from', 'period_to',
                'due_on', 'currency', 'amount_to_pay', 'notes',
            ],
            'properties' => [
                'is_bill' => ['type' => 'boolean'],
                'apartment_id' => ['type' => ['integer', 'null']],
                'match_confidence' => ['type' => 'string', 'enum' => ['high', 'medium', 'low', 'none']],
                'match_reason' => ['type' => 'string'],
                'address_on_invoice' => $nullableString,
                'category' => ['type' => ['string', 'null'], 'enum' => [...array_column(BillCategory::cases(), 'value'), null]],
                'supplier' => $nullableString,
                'invoice_number' => $nullableString,
                'issued_on' => $nullableString,
                'period_from' => $nullableString,
                'period_to' => $nullableString,
                'due_on' => $nullableString,
                'currency' => $nullableString,
                'amount_to_pay' => ['type' => ['number', 'null']],
                'notes' => $nullableString,
            ],
        ];
    }
}
