<?php

namespace App\Enums;

enum BankTransactionStatus: string
{
    /** Clearly from a tenant – ticked for booking. */
    case Suggested = 'suggested';

    /** Might be from a tenant – the owner decides. */
    case Review = 'review';

    /** Not a tenant's payment (refund, own transfer…) or skipped by the owner. */
    case Ignored = 'ignored';

    /** Booked as a payment. */
    case Booked = 'booked';

    public function isPending(): bool
    {
        return in_array($this, [self::Suggested, self::Review], true);
    }
}
