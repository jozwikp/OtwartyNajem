<?php

namespace App\Enums;

enum PaymentMethod: string
{
    case Transfer = 'transfer';
    case Cash = 'cash';

    public function label(): string
    {
        return match ($this) {
            self::Transfer => __('Przelew na konto'),
            self::Cash => __('Gotówka'),
        };
    }
}
