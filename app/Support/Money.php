<?php

namespace App\Support;

use NumberFormatter;

/**
 * Amounts are integers in minor units (1 zł = 100 gr).
 */
class Money
{
    /**
     * Parse what people type ("2 500", "2500,50", "2.500,5") into minor units. Null when it's not a number.
     */
    public static function parse(?string $input): ?int
    {
        $value = preg_replace('/[\s\x{00A0}]/u', '', (string) $input);

        if ($value === '') {
            return null;
        }

        // "2.500,50" -> "2500.50"; "2500,5" -> "2500.5"
        if (str_contains($value, ',')) {
            $value = str_replace('.', '', $value);
            $value = str_replace(',', '.', $value);
        }

        if (! preg_match('/^\d+(\.\d{1,2})?$/', $value)) {
            return null;
        }

        return (int) round(((float) $value) * 100);
    }

    public static function format(int $amount, string $currency): string
    {
        $formatter = new NumberFormatter(static::numberLocale(), NumberFormatter::CURRENCY);

        if ($amount % 100 === 0) {
            $formatter->setAttribute(NumberFormatter::FRACTION_DIGITS, 0);
        }

        return (string) $formatter->formatCurrency($amount / 100, $currency);
    }

    /**
     * ICU locale for number formatting in the interface language.
     */
    public static function numberLocale(): string
    {
        return match (app()->getLocale()) {
            'en' => 'en_GB',
            default => 'pl_PL',
        };
    }

    /**
     * Value for a form field, e.g. 250050 -> "2500,50", 250000 -> "2500".
     */
    public static function toInput(?int $amount): string
    {
        if ($amount === null) {
            return '';
        }

        return $amount % 100 === 0
            ? (string) intdiv($amount, 100)
            : number_format($amount / 100, 2, ',', '');
    }
}
