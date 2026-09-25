<?php

use App\Services\BankStatementParser;

function mbankStatement(array $rows): string
{
    $lines = [
        "\xEF\xBB\xBFmBank S.A. Bankowość Detaliczna;",
        "\t\tSkrytka Pocztowa 2108;",
        '',
        '#Klient;',
        'JAN TESTOWY;',
        '',
        '#Za okres:;',
        '01.08.2026;25.09.2026;',
        '',
        '      #Waluta;#Wpływy;#Wydatki;',
        'PLN;5 100,00;-120,40;',
        '',
        '#Data operacji;#Opis operacji;#Rachunek;#Kategoria;#Kwota;',
        ...$rows,
    ];

    return implode("\r\n", $lines);
}

function mbankRow(string $date, string $description, string $amount): string
{
    return $date.';"'.str_replace('"', '""', $description).'";"PRV 1111 ... 2222";"Bez kategorii";'.$amount.' PLN;;';
}

test('it reads an mBank export and keeps signs, accounts, names and titles', function () {
    $csv = mbankStatement([
        mbankRow('2026-09-10', 'ANNA MATKOWSKA UL. DŁUGA 5/2 78-600 WAŁCZ, za czynsz Ewa Matkowska  ANNA MATKOWSKA        UL. DŁUGA 5/2          78-600    WAŁCZ     PRZELEW ZEWNĘTRZNY PRZYCHODZĄCY                  53102000000000000000001234  ', '2 500,00'),
        mbankRow('2026-09-09', 'BIEDRONKA  ZAKUP PRZY UŻYCIU KARTY W KRAJU                                ', '-120,40'),
        mbankRow('2026-09-08', 'SKLEP XYZ SP. Z O.O., ZWROT Z TYT. 123  PRZELEW ZEWNĘTRZNY PRZYCHODZĄCY  12345678901234567890123456  ', '2 600,00'),
    ]);

    $result = app(BankStatementParser::class)->parse($csv);

    expect($result['bank'])->toBe('mBank')
        ->and($result['rows'])->toHaveCount(3)
        ->and($result['period_from']->toDateString())->toBe('2026-09-08');

    [$rent, $card] = $result['rows'];

    expect($rent['amount'])->toBe(250000)
        ->and($rent['currency'])->toBe('PLN')
        ->and($rent['sender_name'])->toBe('ANNA MATKOWSKA')
        ->and($rent['title'])->toBe('za czynsz Ewa Matkowska')
        ->and($rent['sender_account'])->toBe('PL53102000000000000000001234')
        ->and($card['amount'])->toBe(-12040);
});

test('it reads a PKO BP style export in Windows-1250 with labelled details', function () {
    $csv = implode("\n", [
        '"Data operacji","Data waluty","Typ transakcji","Kwota","Waluta","Saldo po transakcji","Opis transakcji","","",""',
        '"2026-09-05","2026-09-05","Przelew na rachunek","+1800.00","PLN","+5000.00","Rachunek nadawcy: 61 1090 1014 0000 0712 1981 2874","Nazwa nadawcy: Piotr Żółtowski","Tytuł: Czynsz wrzesień Kwiatowa 7/3",""',
        '"2026-09-04","2026-09-04","Płatność kartą","-35.99","PLN","+3200.00","Tytuł: Apteka",""," ",""',
    ]);

    $result = app(BankStatementParser::class)->parse(iconv('UTF-8', 'WINDOWS-1250', $csv));

    expect($result['rows'])->toHaveCount(2);

    $row = $result['rows'][0];
    expect($row['amount'])->toBe(180000)
        ->and($row['sender_name'])->toBe('Piotr Żółtowski')
        ->and($row['title'])->toBe('Czynsz wrzesień Kwiatowa 7/3')
        ->and($row['sender_account'])->toBe('PL61109010140000071219812874')
        ->and($result['rows'][1]['amount'])->toBe(-3599);
});

test('it reads exports with separate credit and debit columns', function () {
    $csv = implode("\n", [
        'Data księgowania;Kontrahent;Tytuł;Uznania;Obciążenia;Waluta',
        '12.09.2026;Marek Nowak;najem 09/2026;1 950,00;;PLN',
        '13.09.2026;Orlen;Paliwo;;210,00;PLN',
    ]);

    $rows = app(BankStatementParser::class)->parse($csv)['rows'];

    expect($rows[0])->toMatchArray(['amount' => 195000, 'sender_name' => 'Marek Nowak', 'title' => 'najem 09/2026'])
        ->and($rows[1]['amount'])->toBe(-21000);
});

test('amounts in different notations are parsed', function (string $input, int $expected) {
    expect(app(BankStatementParser::class)->amount($input)[0])->toBe($expected);
})->with([
    ['-8,40 PLN', -840],
    ['2 500,00', 250000],
    ['1.234,56', 123456],
    ['1,234.56', 123456],
    ['+1800.00', 180000],
    ['300', 30000],
    ["2\u{00A0}500,5", 250050],
]);

test('a file that is not a statement is rejected with a clear message', function () {
    expect(fn () => app(BankStatementParser::class)->parse("imię;nazwisko\nJan;Kowalski"))
        ->toThrow(RuntimeException::class, 'Nie rozpoznaliśmy układu tego pliku');
});
