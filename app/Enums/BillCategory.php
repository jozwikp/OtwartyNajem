<?php

namespace App\Enums;

enum BillCategory: string
{
    case Electricity = 'electricity';
    case Gas = 'gas';
    case Water = 'water';
    case Heating = 'heating';
    case Waste = 'waste';
    case Internet = 'internet';
    case Other = 'other';

    public function label(): string
    {
        return match ($this) {
            self::Electricity => __('Prąd'),
            self::Gas => __('Gaz'),
            self::Water => __('Woda i ścieki'),
            self::Heating => __('Ogrzewanie'),
            self::Waste => __('Śmieci'),
            self::Internet => __('Internet i TV'),
            self::Other => __('Inny rachunek'),
        };
    }

    public function icon(): string
    {
        return match ($this) {
            self::Electricity => 'bolt',
            self::Gas => 'fire',
            self::Water => 'beaker',
            self::Heating => 'sun',
            self::Waste => 'trash',
            self::Internet => 'wifi',
            self::Other => 'document-text',
        };
    }
}
