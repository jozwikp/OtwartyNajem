<?php

namespace App\Enums;

enum ChargeType: string
{
    case Rent = 'rent';
    case AdminFee = 'admin_fee';
    case Other = 'other';

    public function label(): string
    {
        return match ($this) {
            self::Rent => __('Opłata za mieszkanie'),
            self::AdminFee => __('Opłata do administracji / wspólnoty'),
            self::Other => __('Inna opłata'),
        };
    }
}
