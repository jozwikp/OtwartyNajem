<?php

namespace App\Enums;

enum LeaseStatus: string
{
    case Upcoming = 'upcoming';
    case Active = 'active';
    case Ended = 'ended';

    public function label(): string
    {
        return match ($this) {
            self::Upcoming => __('Rozpocznie się'),
            self::Active => __('Trwa'),
            self::Ended => __('Zakończony'),
        };
    }

    public function color(): string
    {
        return match ($this) {
            self::Upcoming => 'sky',
            self::Active => 'green',
            self::Ended => 'zinc',
        };
    }
}
