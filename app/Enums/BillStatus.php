<?php

namespace App\Enums;

enum BillStatus: string
{
    /** Uploaded, waiting for the AI. */
    case Queued = 'queued';

    /** The AI is reading the document. */
    case Processing = 'processing';

    /** Read by the AI, waiting for the owner to approve or correct. */
    case Review = 'review';

    /** The AI could not read it – the owner fills it in by hand. */
    case Failed = 'failed';

    /** Approved: the tenant is charged (if there is a lease for the period). */
    case Approved = 'approved';

    public function label(): string
    {
        return match ($this) {
            self::Queued => __('W kolejce'),
            self::Processing => __('Odczytywanie…'),
            self::Review => __('Do sprawdzenia'),
            self::Failed => __('Nie udało się odczytać'),
            self::Approved => __('Zatwierdzony'),
        };
    }

    public function isPending(): bool
    {
        return in_array($this, [self::Queued, self::Processing], true);
    }
}
