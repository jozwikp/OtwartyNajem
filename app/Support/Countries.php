<?php

namespace App\Support;

use Collator;
use ResourceBundle;

class Countries
{
    /**
     * Countries shown at the top of the list, in this order.
     */
    public const PINNED = ['PL', 'DE', 'CZ', 'SK', 'LT', 'UA', 'GB'];

    /**
     * All countries as ISO code => Polish name, pinned ones first.
     *
     * @return array<string, string>
     */
    public static function all(): array
    {
        return once(function () {
            $bundle = ResourceBundle::create('pl', 'ICUDATA-region');
            $countries = [];

            foreach ($bundle['Countries'] as $code => $name) {
                if (preg_match('/^[A-Z]{2}$/', $code) && ! in_array($code, ['ZZ', 'EU', 'EZ', 'UN', 'QO', 'XA', 'XB'])) {
                    $countries[$code] = $name;
                }
            }

            (new Collator('pl_PL'))->asort($countries);

            $pinned = [];

            foreach (self::PINNED as $code) {
                $pinned[$code] = $countries[$code];
            }

            return $pinned + $countries;
        });
    }

    public static function name(?string $code): string
    {
        return self::all()[$code] ?? (string) $code;
    }

    /**
     * @return list<string>
     */
    public static function codes(): array
    {
        return array_keys(self::all());
    }
}
