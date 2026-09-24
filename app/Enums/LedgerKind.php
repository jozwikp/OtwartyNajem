<?php

namespace App\Enums;

enum LedgerKind: string
{
    case Charge = 'charge';
    case Payment = 'payment';
}
