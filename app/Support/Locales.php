<?php

namespace App\Support;

/**
 * Interface languages users can choose in their settings.
 */
class Locales
{
    public const SUPPORTED = [
        'pl' => 'Polski',
        'en' => 'English',
    ];

    /**
     * @return list<string>
     */
    public static function codes(): array
    {
        return array_keys(self::SUPPORTED);
    }
}
