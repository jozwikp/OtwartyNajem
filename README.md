# SpokojnyNajem

Proste zarządzanie mieszkaniami na wynajem – dla ludzi, nie dla księgowych.

SpokojnyNajem pilnuje za Ciebie czynszu, rachunków i wpłat najemców. Wgrywasz faktury za media, system sam je odczytuje, przypisuje do właściwego mieszkania i dolicza najemcy. Najemca raz dziennie dostaje e-mail z tym, co doszło i ile ma zapłacić.

## Co potrafi

- **Mieszkania** – adres, metraż, własna nazwa na liście. Kilku współwłaścicieli z tymi samymi uprawnieniami (zaproszenia e-mailem).
- **Najem** – najemcy (imię, nazwisko, e-mail, telefon), okres umowy, numer konta, waluta, kaucja (wraz ze zwrotem i powodem potrąceń).
- **Stałe opłaty** – opłata za mieszkanie, do administracji/wspólnoty, inne. Naliczane automatycznie co miesiąc (1. dnia), za niepełny miesiąc proporcjonalnie do dni. Kwoty mogą się zmieniać w czasie (np. podwyżka od listopada), nigdy wstecz.
- **Rachunki z AI** – wgrywasz naraz wiele faktur (PDF lub zdjęcia). Model AI odczytuje kwotę, daty, okres, dostawcę i dopasowuje fakturę do mieszkania – także gdy adres jest zapisany inaczej („m. 5” = „/5”, brak polskich znaków, skróty ulic). Ty tylko klikasz **Zatwierdź** albo **Popraw**.
- **Rozliczenia** – jedno saldo na najem: wpłaty zawsze pokrywają najpierw najstarsze opłaty. Widać, co opłacone, co po terminie i ile zostało do zapłaty.
- **Powiadomienia dla najemców** – jeden e-mail dziennie po 16:00 (tylko gdy coś doszło): nowe opłaty, zmienione kwoty, otrzymane wpłaty, saldo i dane do przelewu. Właściciele dostają kopię.
- **Historia zmian** – każda operacja jest zapisana: kto, kiedy, co zmienił (z wartościami przed i po), z jakiego IP. Także automatyczne naliczenia i wysłane e-maile.

Cały interfejs jest po polsku, trudniejsze operacje są podzielone na krótkie kroki.

## Technologia

Laravel 13 · Livewire 4 · Flux UI · Tailwind CSS 4 · SQLite (domyślnie) · Pest · OpenRouter (AI)

## Wymagania

- PHP 8.4+ (z rozszerzeniami `intl`, `pdo_sqlite`)
- Composer
- Node.js 20+ i npm
- Klucz API [OpenRouter](https://openrouter.ai) – do odczytu faktur

Najprościej na macOS/Windows: [Laravel Herd](https://herd.laravel.com) (ma PHP, Composer i Node).

## Uruchomienie

```bash
git clone https://github.com/jozwikp/SpokojnyNajem.git
cd SpokojnyNajem

composer install
npm install

cp .env.example .env
php artisan key:generate

touch database/database.sqlite
php artisan migrate

npm run build
```

Uzupełnij w pliku `.env`:

```dotenv
OPENROUTER_API_KEY=sk-or-...        # klucz do odczytu faktur przez AI
OPENROUTER_MODEL=openai/gpt-5-nano  # model obsługujący PDF i obrazy

MAIL_MAILER=smtp                    # lokalnie może zostać "log" – maile trafią do storage/logs/laravel.log
MAIL_FROM_ADDRESS="powiadomienia@twoja-domena.pl"
```

Uruchom wszystko jednym poleceniem (serwer, kolejka, logi, Vite):

```bash
composer run dev
```

Aplikacja będzie dostępna pod adresem z `APP_URL` (np. `http://localhost:8000`, a w Herd `http://<nazwa-katalogu>.test`). Załóż konto na stronie rejestracji i dodaj pierwsze mieszkanie.

### Zadania w tle

Dwie rzeczy dzieją się automatycznie i wymagają działających procesów:

| Co | Po co | Lokalnie | Na serwerze |
|---|---|---|---|
| Kolejka | odczyt faktur przez AI | zawiera się w `composer run dev` (albo `php artisan queue:work`) | `php artisan queue:work` pod nadzorem (np. Supervisor) |
| Harmonogram | naliczanie opłat (00:15), e-maile do najemców (16:00) | `php artisan schedule:work` | cron: `* * * * * php artisan schedule:run` |

Opłaty stałe dopisują się też same przy wejściu w zakładkę **Rozliczenia**, więc nic nie zginie, nawet gdy harmonogram chwilowo nie działa.

## Testy

```bash
php artisan test
```

Testy nie łączą się z OpenRouter ani nie wysyłają e-maili – odpowiedzi są symulowane.

## Kilka zasad w kodzie

- Kwoty są zapisywane w groszach (liczby całkowite), zawsze w walucie najmu – system nie przyjmie operacji w innej walucie.
- Saldo nie jest nigdzie przechowywane: to suma naliczeń minus suma wpłat z tabeli `ledger_entries`.
- Logika biznesowa jest w `app/Actions`, odczyt faktur w `app/Services/BillReader.php`, ekrany w `resources/views/pages`.
- Plik `.env` (klucze, hasła) nigdy nie trafia do repozytorium.
