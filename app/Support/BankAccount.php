<?php

namespace App\Support;

class BankAccount
{
    /**
     * Remove spaces and dashes; a bare 26-digit Polish account number gets the "PL" prefix.
     */
    public static function normalize(?string $input): string
    {
        $value = strtoupper(preg_replace('/[\s\-]/', '', (string) $input));

        if (preg_match('/^\d{26}$/', $value)) {
            $value = 'PL'.$value;
        }

        return $value;
    }

    /**
     * IBAN checksum (mod 97).
     */
    public static function isValid(string $iban): bool
    {
        if (! preg_match('/^[A-Z]{2}\d{2}[A-Z0-9]{10,30}$/', $iban)) {
            return false;
        }

        $rearranged = substr($iban, 4).substr($iban, 0, 4);
        $numeric = preg_replace_callback('/[A-Z]/', fn ($m) => (string) (ord($m[0]) - 55), $rearranged);

        $remainder = 0;
        foreach (str_split($numeric, 7) as $chunk) {
            $remainder = (int) ($remainder.$chunk) % 97;
        }

        return $remainder === 1;
    }

    /**
     * Human-friendly format: Polish accounts as "61 1090 1014 0000 0712 1981 2874", others in groups of four.
     */
    public static function format(?string $iban): string
    {
        if (blank($iban)) {
            return '';
        }

        if (str_starts_with($iban, 'PL')) {
            $digits = substr($iban, 2);

            return substr($digits, 0, 2).' '.trim(chunk_split(substr($digits, 2), 4, ' '));
        }

        return trim(chunk_split($iban, 4, ' '));
    }
}
