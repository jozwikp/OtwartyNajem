<?php

namespace App\Support;

class Currencies
{
    public const DEFAULT = 'PLN';

    /**
     * @return array<string, string>
     */
    public static function all(): array
    {
        return [
            'PLN' => __('złoty polski (PLN)'),
            'EUR' => __('euro (EUR)'),
            'USD' => __('dolar amerykański (USD)'),
            'GBP' => __('funt brytyjski (GBP)'),
            'CHF' => __('frank szwajcarski (CHF)'),
            'CZK' => __('korona czeska (CZK)'),
            'SEK' => __('korona szwedzka (SEK)'),
            'NOK' => __('korona norweska (NOK)'),
            'DKK' => __('korona duńska (DKK)'),
            'UAH' => __('hrywna (UAH)'),
        ];
    }

    /**
     * @return list<string>
     */
    public static function codes(): array
    {
        return array_keys(self::all());
    }

    /**
     * Short symbol used as an input suffix, e.g. "zł" or "€".
     */
    public static function symbol(string $currency): string
    {
        return match ($currency) {
            'PLN' => 'zł',
            'EUR' => '€',
            'USD' => '$',
            'GBP' => '£',
            default => $currency,
        };
    }
}
