<?php

namespace App\Actions\Leases;

use App\Models\Lease;
use Carbon\CarbonImmutable;

/**
 * Ends the lease on a given day (e.g. early termination). The last month is
 * recalculated proportionally and later months are no longer charged.
 */
class EndLease
{
    public function __construct(protected UpdateLease $updateLease) {}

    public function handle(Lease $lease, CarbonImmutable $endsOn): void
    {
        $this->updateLease->handle($lease, ['terminated_on' => $endsOn]);
    }
}
