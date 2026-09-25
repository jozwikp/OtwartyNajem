<?php

namespace App\Services;

use Carbon\CarbonImmutable;
use Illuminate\Support\Str;
use RuntimeException;
use Throwable;

/**
 * Reads a bank statement exported as CSV (mBank and similar layouts of other Polish banks).
 * Finds the header row, recognises the columns by their names and returns every operation
 * with a signed amount in minor units.
 */
class BankStatementParser
{
    /**
     * Column names (lowercase, without "#") recognised in bank exports.
     *
     * @var array<string, list<string>>
     */
    protected const COLUMNS = [
        'date' => ['data operacji', 'data transakcji', 'data księgowania', 'data ksiegowania', 'data'],
        'amount' => ['kwota', 'kwota operacji', 'kwota transakcji', 'kwota w walucie rachunku', 'kwota w walucie'],
        'credit' => ['uznania', 'wpływy', 'wplywy', 'uznanie'],
        'debit' => ['obciążenia', 'obciazenia', 'wydatki', 'obciążenie'],
        'description' => ['opis operacji', 'opis transakcji', 'opis', 'szczegóły', 'szczegoly'],
        'title' => ['tytuł', 'tytul', 'tytuł operacji', 'tytuł przelewu', 'tytułem'],
        'counterparty' => ['nadawca / odbiorca', 'nadawca/odbiorca', 'kontrahent', 'dane kontrahenta', 'nazwa kontrahenta', 'nadawca', 'odbiorca/zleceniodawca', 'zleceniodawca'],
        'counterparty_account' => ['rachunek nadawcy', 'rachunek kontrahenta', 'nr rachunku kontrahenta', 'numer rachunku kontrahenta', 'konto kontrahenta', 'rachunek nadawcy / odbiorcy', 'nr rachunku nadawcy/odbiorcy'],
        'currency' => ['waluta'],
    ];

    /** @var list<int> */
    protected array $extraColumns = [];

    /**
     * @return array{bank: ?string, period_from: ?CarbonImmutable, period_to: ?CarbonImmutable, rows: list<array{booked_on: CarbonImmutable, amount: int, currency: string, description: string, sender_name: ?string, sender_account: ?string, title: ?string}>}
     */
    public function parse(string $contents): array
    {
        $text = $this->toUtf8($contents);
        $lines = preg_split('/\r\n|\r|\n/', $text) ?: [];
        $delimiter = $this->detectDelimiter($lines);

        [$headerIndex, $columns] = $this->findHeader($lines, $delimiter);

        $rows = [];
        foreach ($this->records(array_slice($lines, $headerIndex + 1), $delimiter) as $cells) {
            if ($row = $this->row($cells, $columns)) {
                $rows[] = $row;
            }
        }

        if ($rows === []) {
            throw new RuntimeException(__('W pliku nie znaleźliśmy żadnych operacji.'));
        }

        $dates = array_map(fn ($row) => $row['booked_on'], $rows);

        return [
            'bank' => $this->detectBank($text),
            'period_from' => min($dates),
            'period_to' => max($dates),
            'rows' => $rows,
        ];
    }

    protected function toUtf8(string $contents): string
    {
        $contents = preg_replace('/^\xEF\xBB\xBF/', '', $contents) ?? $contents;

        // Many Polish banks (e.g. PKO BP) export in Windows-1250.
        if (! mb_check_encoding($contents, 'UTF-8')) {
            $contents = iconv('WINDOWS-1250', 'UTF-8//IGNORE', $contents) ?: $contents;
        }

        return $contents;
    }

    /**
     * @param  list<string>  $lines
     */
    protected function detectDelimiter(array $lines): string
    {
        $sample = implode("\n", array_slice($lines, 0, 60));

        $counts = ['\;' => substr_count($sample, ';'), ',' => substr_count($sample, ','), "\t" => substr_count($sample, "\t")];
        arsort($counts);

        return str_replace('\\', '', (string) array_key_first($counts));
    }

    /**
     * @param  list<string>  $lines
     * @return array{0: int, 1: array<string, int>}
     */
    protected function findHeader(array $lines, string $delimiter): array
    {
        foreach (array_slice($lines, 0, 80, true) as $index => $line) {
            $cells = array_map(fn ($cell) => $this->normalizeHeader((string) $cell), str_getcsv($line, $delimiter, '"', ''));
            $columns = [];

            foreach (self::COLUMNS as $key => $names) {
                foreach ($names as $name) {
                    $position = array_search($name, $cells, true);
                    if ($position !== false && ! in_array($position, $columns, true)) {
                        $columns[$key] = (int) $position;
                        break;
                    }
                }
            }

            $hasAmount = isset($columns['amount']) || (isset($columns['credit']) && isset($columns['debit']));

            if (isset($columns['date']) && $hasAmount && (isset($columns['description']) || isset($columns['title']) || isset($columns['counterparty']))) {
                // PKO BP: details continue in columns without a name after the description.
                $this->extraColumns = array_keys(array_filter($cells, fn ($name, $position) => $name === '' && $position > ($columns['description'] ?? PHP_INT_MAX), ARRAY_FILTER_USE_BOTH));

                return [$index, $columns];
            }
        }

        throw new RuntimeException(__('Nie rozpoznaliśmy układu tego pliku. Upewnij się, że to wyciąg w formacie CSV z kolumnami daty, kwoty i opisu.'));
    }

    protected function normalizeHeader(string $cell): string
    {
        return trim(mb_strtolower(trim($cell, " \t#\"'")), ' :');
    }

    /**
     * CSV records – a quoted value may span several lines.
     *
     * @param  list<string>  $lines
     * @return iterable<list<string>>
     */
    protected function records(array $lines, string $delimiter): iterable
    {
        $buffer = '';

        foreach ($lines as $line) {
            $buffer = $buffer === '' ? $line : $buffer."\n".$line;

            if (substr_count($buffer, '"') % 2 === 1) {
                continue;
            }

            if (trim($buffer) !== '') {
                yield array_map('strval', str_getcsv($buffer, $delimiter, '"', ''));
            }

            $buffer = '';
        }
    }

    /**
     * @param  list<string>  $cells
     * @param  array<string, int>  $columns
     * @return array{booked_on: CarbonImmutable, amount: int, currency: string, description: string, sender_name: ?string, sender_account: ?string, title: ?string}|null
     */
    protected function row(array $cells, array $columns): ?array
    {
        $cell = fn (string $key): string => isset($columns[$key]) ? trim($cells[$columns[$key]] ?? '') : '';

        $date = $this->date($cell('date'));
        if (! $date) {
            return null;
        }

        if (isset($columns['amount'])) {
            [$amount, $currency] = $this->amount($cell('amount'));
        } else {
            [$credit, $currency] = $this->amount($cell('credit'));
            [$debit] = $this->amount($cell('debit'));
            $amount = $credit !== null && $credit !== 0 ? abs($credit) : ($debit !== null ? -abs($debit) : null);
        }

        if ($amount === null) {
            return null;
        }

        $currency = strtoupper($cell('currency')) ?: ($currency ?? 'PLN');

        $rawDescription = $cell('description');
        foreach ($this->extraColumns as $position) {
            if (trim($cells[$position] ?? '') !== '') {
                $rawDescription .= '  '.trim($cells[$position]);
            }
        }

        $labelled = $this->labelledFields($rawDescription);
        $description = $this->squash(implode('  ', array_filter([$cell('counterparty'), $cell('title'), $rawDescription])));

        return [
            'booked_on' => $date,
            'amount' => $amount,
            'currency' => preg_match('/^[A-Z]{3}$/', $currency) ? $currency : 'PLN',
            'description' => $description,
            'sender_name' => $labelled['name'] ?? $this->senderName($cell('counterparty'), $rawDescription),
            'sender_account' => $this->account($cell('counterparty_account')) ?? $this->account($labelled['account'] ?? '') ?? $this->accountIn($rawDescription),
            'title' => $labelled['title'] ?? $this->title($cell('title'), $rawDescription),
        ];
    }

    /**
     * PKO BP style details: "Nazwa nadawcy: …", "Tytuł: …", "Rachunek nadawcy: …".
     *
     * @return array{name?: string, title?: string, account?: string}
     */
    protected function labelledFields(string $description): array
    {
        $fields = [];
        $labels = [
            'name' => 'Nazwa nadawcy|Nadawca|Dane nadawcy|Nazwa kontrahenta',
            'title' => 'Tytuł|Tytul',
            'account' => 'Rachunek nadawcy|Numer rachunku nadawcy|Nr rachunku nadawcy',
        ];

        foreach ($labels as $key => $pattern) {
            if (preg_match('/(?:^|\s)(?:'.$pattern.')\s*:\s*(.+?)(?=\s{2,}|\s+(?:Nazwa nadawcy|Nadawca|Adres nadawcy|Tytuł|Tytul|Rachunek nadawcy|Lokalizacja|Referencje)\s*:|$)/iu', $description, $m)) {
                $fields[$key] = Str::limit($this->squash($m[1]), 250, '');
            }
        }

        return $fields;
    }

    protected function date(string $value): ?CarbonImmutable
    {
        foreach (['Y-m-d', 'd.m.Y', 'd-m-Y', 'd/m/Y', 'Y.m.d', 'Y/m/d'] as $format) {
            try {
                $date = CarbonImmutable::createFromFormat('!'.$format, $value);
            } catch (Throwable) {
                continue;
            }

            if ($date && $date->format($format) === $value) {
                return $date;
            }
        }

        return null;
    }

    /**
     * "-8,40 PLN", "2 500,00", "+1.234,56", "1234.56" → [minor units, currency]
     *
     * @return array{0: ?int, 1: ?string}
     */
    public function amount(string $value): array
    {
        $currency = preg_match('/([A-Z]{3})\s*$/', $value, $m) ? $m[1] : null;
        $number = preg_replace('/[^\d,.\-+]/', '', $value) ?? '';

        if ($number === '' || ! preg_match('/\d/', $number)) {
            return [null, $currency];
        }

        $negative = str_starts_with($number, '-');
        $number = ltrim($number, '+-');

        // Decimal separator is the last "," or "."; the other one groups thousands.
        $lastComma = strrpos($number, ',');
        $lastDot = strrpos($number, '.');
        $decimalPosition = max($lastComma === false ? -1 : $lastComma, $lastDot === false ? -1 : $lastDot);

        if ($decimalPosition >= 0 && strlen($number) - $decimalPosition - 1 <= 2) {
            $whole = preg_replace('/[,.]/', '', substr($number, 0, $decimalPosition)) ?? '';
            $fraction = str_pad(substr($number, $decimalPosition + 1), 2, '0');
        } else {
            $whole = preg_replace('/[,.]/', '', $number) ?? '';
            $fraction = '00';
        }

        $minor = (int) $whole * 100 + (int) $fraction;

        return [$negative ? -$minor : $minor, $currency];
    }

    protected function squash(string $value): string
    {
        return trim(preg_replace('/\s+/u', ' ', $value) ?? $value);
    }

    protected function account(string $value): ?string
    {
        $value = strtoupper(preg_replace('/[\s\-]/', '', $value) ?? '');

        return match (true) {
            (bool) preg_match('/^\d{26}$/', $value) => 'PL'.$value,
            (bool) preg_match('/^[A-Z]{2}\d{2}[A-Z0-9]{10,30}$/', $value) => $value,
            default => null,
        };
    }

    /**
     * The counterparty's account written inside the description (mBank puts it at the end).
     */
    protected function accountIn(string $description): ?string
    {
        preg_match_all('/(?<![\d])(?:PL)?\d{26}(?![\d])/', $description, $matches);

        $last = end($matches[0]);

        return $last ? $this->account($last) : null;
    }

    /**
     * mBank: "JAN KOWALSKI UL. DŁUGA 1 00-001 WARSZAWA, tytuł przelewu  JAN KOWALSKI  UL. …"
     */
    protected function senderName(string $counterparty, string $description): ?string
    {
        if ($counterparty !== '') {
            return Str::limit($this->squash($counterparty), 250, '');
        }

        $head = trim(Str::before($description, ','));
        if ($head === '' || $head === $description) {
            $head = trim($this->firstPart('/\s{2,}/', $description));
        }

        // Cut the street address off the name.
        $name = $this->firstPart('/\s+(UL\.|AL\.|OS\.|PL\.|ULICA|ALEJA|\d{2}-\d{3})/u', $head);
        $name = trim(preg_replace('/\b(PRZELEW (ZEWNĘTRZNY|WEWNĘTRZNY) PRZYCHODZĄCY)\b/u', '', $name) ?? $name);

        return $name !== '' ? Str::limit($this->squash($name), 250, '') : null;
    }

    protected function title(string $title, string $description): ?string
    {
        if ($title !== '') {
            return Str::limit($this->squash($title), 250, '');
        }

        if (str_contains($description, ',')) {
            $afterComma = trim($this->firstPart('/\s{2,}/', Str::after($description, ',')));

            return $afterComma !== '' ? Str::limit($this->squash($afterComma), 250, '') : null;
        }

        return null;
    }

    /**
     * The text before the first match of the pattern.
     */
    protected function firstPart(string $pattern, string $subject): string
    {
        $parts = preg_split($pattern, $subject);

        return $parts === false ? $subject : $parts[0];
    }

    protected function detectBank(string $text): ?string
    {
        $head = mb_strtolower(mb_substr($text, 0, 2000));

        foreach (['mbank' => 'mBank', 'pko' => 'PKO BP', 'ing bank' => 'ING', 'santander' => 'Santander', 'pekao' => 'Pekao', 'millennium' => 'Millennium', 'alior' => 'Alior', 'credit agricole' => 'Credit Agricole', 'bnp' => 'BNP Paribas'] as $needle => $name) {
            if (str_contains($head, $needle)) {
                return $name;
            }
        }

        return null;
    }
}
