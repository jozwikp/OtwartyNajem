# OtwartyNajem

Proste zarządzanie mieszkaniami na wynajem – dla ludzi, nie dla księgowych.

🌐 [otwartynajem.pl](https://otwartynajem.pl)

OtwartyNajem pilnuje za Ciebie czynszu, rachunków i wpłat najemców. Wgrywasz faktury za media, system sam je odczytuje, przypisuje do właściwego mieszkania i dolicza najemcy. Wgrywasz wyciąg z banku, system sam rozpoznaje przelewy od najemców. Najemca raz dziennie dostaje e-mail z tym, co doszło i ile ma zapłacić.

## Co potrafi

- **Mieszkania** – adres, metraż, własna nazwa na liście. Kilku współwłaścicieli z tymi samymi uprawnieniami (zaproszenia e-mailem).
- **Najem** – najemcy (imię, nazwisko, e-mail, telefon), okres umowy, numer konta, waluta, kaucja (wraz ze zwrotem i powodem potrąceń).
- **Stałe opłaty** – opłata za mieszkanie, do administracji/wspólnoty, inne. Naliczane automatycznie co miesiąc (1. dnia), za niepełny miesiąc proporcjonalnie do dni. Kwoty mogą się zmieniać w czasie (np. podwyżka od listopada), nigdy wstecz.
- **Rachunki z AI** – wgrywasz naraz wiele faktur (PDF lub zdjęcia). Model AI odczytuje kwotę, daty, okres, dostawcę i dopasowuje fakturę do mieszkania – także gdy adres jest zapisany inaczej („m. 5” = „/5”, brak polskich znaków, skróty ulic). Ty tylko klikasz **Zatwierdź** albo **Popraw**.
- **Wpłaty** – dwie drogi:
  - **ręcznie**: wybierasz najemcę (system podpowiada, ile ma do zapłaty), wpisujesz kwotę i datę;
  - **z wyciągu bankowego (CSV)**: wgrywasz plik z bankowości internetowej (mBank, PKO BP i podobne układy), system bierze tylko wpływy i sam dopasowuje je do najemców – po imieniu i nazwisku w nadawcy lub tytule (bez względu na kolejność i polskie znaki), po adresie mieszkania, kwocie należności i po kontach, z których już wcześniej płacono (np. rodzic płacący za najemcę). Pewne wpłaty są od razu zaznaczone, wątpliwe czekają na Twoją decyzję, a Ty jednym kliknięciem księgujesz wszystko naraz.
- **Rozliczenia** – jedno saldo na najem: wpłaty zawsze pokrywają najpierw najstarsze opłaty. Widać, co opłacone, co po terminie i ile zostało do zapłaty.
- **Powiadomienia dla najemców** – jeden e-mail dziennie po 16:00 (tylko gdy coś doszło): nowe opłaty, zmienione kwoty, otrzymane wpłaty, saldo i dane do przelewu. Właściciele dostają kopię.
- **Historia zmian** – każda operacja jest zapisana: kto, kiedy, co zmienił (z wartościami przed i po), z jakiego IP. Także automatyczne naliczenia i wysłane e-maile.

Interfejs jest **po polsku i po angielsku** – każdy użytkownik wybiera język w *Ustawienia → Wygląd i język* (goście dostają język przeglądarki). Trudniejsze operacje są podzielone na krótkie kroki. Każdy najemca dostaje e-maile w swoim języku (polski lub angielski), niezależnie od języka właściciela.

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
git clone https://github.com/jozwikp/OtwartyNajem.git
cd OtwartyNajem

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

ADMIN_EMAIL=admin@twoja-domena.pl   # powiadomienie o każdym nowym koncie (puste = wyłączone)
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
| Kolejka | odczyt faktur przez AI | zawiera się w `composer run dev` (albo `php artisan queue:work`) | uruchamiana przez harmonogram (co minutę, zawsze jeden proces) – nic nie trzeba dodawać |
| Harmonogram | naliczanie opłat (00:15), e-maile do najemców (16:00) | `php artisan schedule:work` | cron: `* * * * * php artisan schedule:run` |

Opłaty stałe dopisują się też same przy wejściu w zakładkę **Rozliczenia**, więc nic nie zginie, nawet gdy harmonogram chwilowo nie działa.

## Import wyciągów bankowych

1. W bankowości internetowej wyeksportuj historię operacji do pliku **CSV** (np. mBank: *Historia → Eksportuj → CSV*).
2. W aplikacji wejdź w **Wpłaty** i wybierz plik.
3. Sprawdź listę i kliknij **Zaksięguj zaznaczone**.

Warto wiedzieć:

- **Sam plik nie jest zapisywany.** Z wyciągu zostają tylko wpływy – Twoje wydatki są pomijane.
- **Ten sam przelew nigdy nie zostanie zaksięgowany dwa razy.** Możesz wgrywać wyciągi nachodzące na siebie (np. sierpień–wrzesień, a potem wrzesień–październik) – dojdą tylko nowe przelewy.
- **Niezałatwione przelewy czekają**, aż je zaksięgujesz albo oznaczysz „To nie od najemcy”. Licznik przy pozycji *Wpłaty* w menu pokazuje, ile ich jest.
- **System uczy się płatników.** Po pierwszym zaksięgowaniu przelewu z danego konta kolejne przelewy z niego rozpoznają się same, nawet bez nazwiska najemcy w tytule.
- **Kaucje** są oznaczane do decyzji – kaucja nie jest wliczana do rozliczeń najmu.
- Dopasowanie odbywa się **lokalnie, bez AI** – wyciąg nie jest nigdzie wysyłany.

## Bezpieczeństwo

### Konta i logowanie

- Hasła przechowywane wyłącznie jako skrót (bcrypt). W środowisku produkcyjnym wymagane: min. 12 znaków, małe i wielkie litery, cyfry, symbole oraz sprawdzenie w bazie wycieków (Have I Been Pwned).
- Nowe konto trzeba potwierdzić linkiem wysłanym e-mailem – do tego czasu aplikacja jest niedostępna. To samo po zmianie adresu e-mail w profilu.
- Weryfikacja dwuetapowa (TOTP, kody zapasowe) i logowanie kluczami dostępu (passkeys) – w *Ustawienia → Bezpieczeństwo*.
- Limit prób: 5 logowań na minutę na e-mail i adres IP, 5 prób kodu 2FA na minutę.
- Ochrona CSRF, ciasteczka sesji `HttpOnly` i `SameSite=Lax`, sesje w bazie danych.
- Nagłówki bezpieczeństwa: aplikacji nie da się osadzić na cudzej stronie (`X-Frame-Options`, `frame-ancestors`), a `Content-Security-Policy` pozwala uruchamiać tylko skrypty z serwera aplikacji lub z jednorazowym kodem (nonce) danej odpowiedzi – wstrzyknięty `<script>` się nie wykona.
- Logowania, wylogowania i zmiany hasła trafiają do historii.

### Dostęp do danych

- Każdy widzi wyłącznie mieszkania, których jest właścicielem lub współwłaścicielem (reguły dostępu sprawdzane przy każdej stronie i każdej akcji).
- Najmy i rachunki są sprawdzane w kontekście mieszkania – nie da się ich otworzyć przez cudzy adres URL.
- Faktury nie są dostępne publicznie: leżą poza katalogiem `public/`, a plik jest wydawany dopiero po sprawdzeniu uprawnień.
- Zaproszenia współwłaścicieli: w bazie tylko skrót tokenu (SHA-256), link ważny 14 dni, można go anulować lub wysłać ponownie.
- Wynik AI jest weryfikowany: model może wskazać tylko mieszkanie osoby, która wgrała fakturę.

### Szyfrowanie danych osobowych

Dane osobowe są **szyfrowane w bazie** kluczem aplikacji (`APP_KEY`, AES-256-CBC z HMAC):

- najemcy – imię, nazwisko, e-mail, telefon (telefon jest opcjonalny),
- numery kont bankowych (najmu i zapamiętanych płatników), notatki i uwagi,
- wpływy z wyciągów – nadawca, konto, tytuł, opis,
- to, co AI odczytało z faktur, oraz cała historia zmian (wartości przed/po, adresy e-mail),
- **pliki faktur** na dysku.

Kradzież samej bazy albo kopii zapasowej nie ujawnia tych danych. Aplikacja odszyfrowuje je w locie. Konta płatników są wyszukiwane po skrócie HMAC (tzw. blind index), więc nie trzeba ich odszyfrowywać do porównania. Jawne pozostają e-maile kont właścicieli (logowanie) i adresy mieszkań (wyszukiwanie).

> **Klucz `APP_KEY` jest jedynym sposobem odczytania danych.** Zapisz go w bezpiecznym miejscu (np. menedżer haseł) **osobno od kopii zapasowych bazy**. Utrata klucza = utrata danych. Nie zmieniaj go ręcznie – do wymiany klucza służy `APP_PREVIOUS_KEYS` (stary klucz dalej odczytuje dane). Po wymianie klucza zapamiętane konta płatników przestaną być rozpoznawane (ich skróty liczone są z klucza) – system nauczy się ich ponownie przy kolejnym księgowaniu.

> **SQLite:** po usunięciu lub nadpisaniu danych ich stare wersje mogą zostać w wolnych stronach pliku bazy. Po masowym usuwaniu danych uruchom `sqlite3 database/database.sqlite "VACUUM;"`.

### Minimalizacja danych

- **Wyciągi bankowe:** plik nie jest zapisywany; zostają wyłącznie wpływy, wydatki właściciela są odrzucane w chwili wczytania. Wyciąg nie jest wysyłany do AI – dopasowanie działa lokalnie.
- **Najemca:** wymagane są tylko imię i nazwisko; e-mail jest potrzebny jedynie do powiadomień, telefon nie jest potrzebny wcale.
- **E-maile do najemców** nie zawierają załączników; odpowiedzi trafiają do właścicieli.

### Rozliczalność

Historia zmian zapisuje każdą operację: kto, kiedy, co zmienił (wartości przed i po), z jakiego IP i przeglądarki – także działania automatyczne (naliczenia, odczyt AI, wysłane e-maile). Historia jest zaszyfrowana.

### Jakość kodu

Przy każdym `git push` GitHub Actions uruchamia formatowanie (Pint), analizę statyczną (PHPStan, poziom 7) i testy (m.in. testy sprawdzające, że dane osobowe nie występują jawnie w bazie ani w plikach). Dependabot co tydzień sprawdza aktualizacje akcji GitHuba.

## RODO – jeśli uruchamiasz własną instancję

> To nie jest porada prawna. Poniżej lista tematów, które trzeba zamknąć; dokumenty warto dać do przejrzenia prawnikowi zajmującemu się ochroną danych.

### Kto jest kim

| Sytuacja | Twoja rola |
|---|---|
| Używasz aplikacji do **własnych mieszkań** | jesteś **administratorem** danych swoich najemców i osób, które za nich płacą. RODO obowiązuje także przy prywatnym wynajmie. |
| Udostępniasz aplikację **innym właścicielom** | wobec danych ich najemców jesteś **podmiotem przetwarzającym** (potrzebna umowa powierzenia z każdym użytkownikiem), a wobec danych samych użytkowników – **administratorem**. |

### Jakie dane przetwarza aplikacja

| Kategoria | Czyje | Gdzie |
|---|---|---|
| Konto: imię, e-mail, hash hasła, IP logowań | użytkownicy (właściciele) | `users`, `sessions`, historia |
| Najemca: imię, nazwisko, e-mail, telefon | najemcy | `lease_tenants` (zaszyfrowane) |
| Rozliczenia: kwoty, terminy, zaległości | najemcy | `ledger_entries`, `bills` |
| Faktury za media (adres, nazwiska, zużycie) | najemcy, właściciele | pliki w `storage/app/private` (zaszyfrowane) |
| Wpływy z wyciągów: nadawca, konto, tytuł | najemcy **i osoby płacące za nich** | `bank_transactions`, `lease_payers` (zaszyfrowane) |
| Historia zmian: kto, co, kiedy, IP | wszyscy | `activity_log` (zaszyfrowane) |

### Zewnętrzni dostawcy (odbiorcy danych)

| Dostawca | Co dostaje | Na co zwrócić uwagę |
|---|---|---|
| **OpenRouter → dostawca modelu** (domyślnie OpenAI) | pełne pliki faktur | umowa powierzenia (DPA) z OpenRouter; w ustawieniach OpenRouter wybierz dostawców **bez przechowywania danych** (zero data retention); transfer do USA wymaga podstawy (EU–US Data Privacy Framework lub standardowe klauzule umowne); poinformuj o tym w polityce prywatności. Jeśli nie chcesz wysyłać faktur – dodawaj rachunki ręcznie. |
| Hosting | wszystko | serwer w UE, DPA z dostawcą |
| Poczta (SMTP, Resend, Postmark…) | e-maile najemców i treść wiadomości | DPA, najlepiej region UE |

### Dokumenty i procedury

- [ ] **Polityka prywatności** – administrator, cele, podstawy prawne, odbiorcy (tabela wyżej), transfer poza UE, okresy przechowywania, prawa osób.
- [ ] **Regulamin** usługi (w Polsce wymagany przy usługach świadczonych drogą elektroniczną) – jeśli udostępniasz aplikację innym.
- [ ] **Umowa powierzenia (DPA)** z każdym użytkownikiem – jeśli udostępniasz aplikację innym; z listą podwykonawców.
- [ ] **Klauzula informacyjna dla najemców** (art. 14 RODO) – najemcy nie mają kont, więc informuje ich właściciel (np. w umowie najmu).
- [ ] **Rejestr czynności przetwarzania** (i rejestr kategorii przetwarzania, jeśli jesteś procesorem).
- [ ] **Procedura naruszeń** – zgłoszenie do UODO w ciągu 72 godzin.
- [ ] **Obsługa żądań osób** (dostęp, sprostowanie, usunięcie) – na razie ręcznie, patrz niżej.

### Konfiguracja produkcyjna

- [ ] `APP_ENV=production`, `APP_DEBUG=false` (inaczej błędy pokazują szczegóły serwera).
- [ ] HTTPS oraz `SESSION_SECURE_COOKIE=true`.
- [ ] Za Cloudflare lub innym proxy: `TRUSTED_PROXIES=cloudflare` (albo adresy proxy) – inaczej limity logowań i historia zmian widzą adres proxy zamiast adresu użytkownika.
- [ ] Silny, unikalny `APP_KEY` przechowywany osobno od kopii zapasowych.
- [ ] Szyfrowane i regularnie testowane kopie zapasowe (baza **i** `storage/app/private`).
- [ ] Rozważ PostgreSQL/MySQL zamiast pliku SQLite przy większej liczbie użytkowników.
- [ ] HSTS na serwerze WWW lub w Cloudflare (`X-Frame-Options`, `Content-Security-Policy` i pozostałe nagłówki wysyła sama aplikacja).
- [ ] Regularne aktualizacje zależności (`composer update`, `npm update`).

### Czego aplikacja jeszcze nie robi (znane braki)

Przed udostępnieniem aplikacji obcym osobom warto to uzupełnić:

- **Brak akceptacji regulaminu i polityki prywatności** przy rejestracji.
- **Usuwanie jest „miękkie”** – usunięte mieszkania, najmy i rachunki zostają w bazie (`deleted_at`). Brak trwałego usuwania na żądanie.
- **Brak automatycznego czyszczenia** starych danych: historia zmian (`clean_after_days` jest ustawione, ale polecenie `activitylog:clean` nie jest zaplanowane), pominięte wpływy z wyciągów, dane najemców po zakończeniu najmu.
- **Brak eksportu danych** (prawo dostępu i przenoszenia) – obecnie tylko ręcznie z bazy.
- **Usunięcie konta jedynego właściciela** zostawia jego mieszkania bez właściciela.
- **Link zaproszenia** może przyjąć każdy zalogowany, kto go posiada (bez sprawdzenia zgodności e-maila).
- **Brak wyłącznika AI** i informacji dla użytkownika przy wgrywaniu faktur, że trafiają do zewnętrznej usługi.

## Testy

```bash
php artisan test
```

Pełne sprawdzenie – formatowanie kodu (Pint), analiza typów (PHPStan) i testy – tak jak na GitHubie:

```bash
composer test
```

Testy nie łączą się z OpenRouter ani nie wysyłają e-maili – odpowiedzi są symulowane.

## Kilka zasad w kodzie

- Kwoty są zapisywane w groszach (liczby całkowite), zawsze w walucie najmu – system nie przyjmie operacji w innej walucie.
- Saldo nie jest nigdzie przechowywane: to suma naliczeń minus suma wpłat z tabeli `ledger_entries`.
- Logika biznesowa jest w `app/Actions`, odczyt faktur w `app/Services/BillReader.php`, odczyt wyciągów w `app/Services/BankStatementParser.php`, dopasowanie wpłat w `app/Support/PaymentMatcher.php`, ekrany w `resources/views/pages`.
- Plik `.env` (klucze, hasła) nigdy nie trafia do repozytorium.
- Teksty interfejsu są w kodzie po polsku (`__('Zapisz')`), tłumaczenia angielskie w `lang/en.json`. Teksty starter kita mają klucze angielskie z tłumaczeniem w `lang/pl.json`. Nowy tekst w kodzie = nowa pozycja w `lang/en.json` (test sprawdza, że na angielskich ekranach nie zostało nic po polsku).

## Licencja

OtwartyNajem jest udostępniony na licencji **MIT** – zobacz plik [LICENSE](LICENSE).

W skrócie: możesz za darmo używać, zmieniać, rozpowszechniać i wykorzystywać kod komercyjnie (także we własnych produktach), pod warunkiem zachowania informacji o autorze i treści licencji. Oprogramowanie jest dostarczane „tak jak jest”, bez żadnych gwarancji – autor nie odpowiada za szkody wynikające z jego użycia, w tym za błędy w naliczeniach czy rozliczeniach.

Licencja obejmuje kod. Nie daje prawa do posługiwania się nazwą „OtwartyNajem” ani logo w sposób sugerujący, że Twoja wersja lub usługa pochodzi od autorów projektu.
