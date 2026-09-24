<?php

namespace App\Actions\Leases;

use App\Models\Apartment;
use Carbon\CarbonImmutable;
use Illuminate\Validation\ValidationException;

/**
 * An apartment can only have one lease at a time.
 */
class EnsureNoOverlap
{
    public function handle(Apartment $apartment, CarbonImmutable $startsOn, ?CarbonImmutable $endsOn, ?int $exceptLeaseId = null, string $field = 'starts_on'): void
    {
        $other = $apartment->overlappingLease($startsOn, $endsOn, $exceptLeaseId);

        if (! $other) {
            return;
        }

        $otherEnd = $other->effectiveEndsOn();

        throw ValidationException::withMessages([
            $field => $otherEnd
                ? __('W tym czasie trwa inny najem (:from – :to). Mieszkanie może mieć tylko jeden najem naraz.', [
                    'from' => $other->starts_on->format('d.m.Y'),
                    'to' => $otherEnd->format('d.m.Y'),
                ])
                : __('Mieszkanie ma już najem bezterminowy od :from. Najpierw zakończ go na karcie najmu.', [
                    'from' => $other->starts_on->format('d.m.Y'),
                ]),
        ]);
    }
}
