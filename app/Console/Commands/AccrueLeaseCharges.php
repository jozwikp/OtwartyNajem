<?php

namespace App\Console\Commands;

use App\Actions\Leases\AccrueRecurringCharges;
use App\Models\Lease;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;

#[Signature('leases:accrue')]
#[Description('Charge fixed monthly fees (rent, administration, other) for all leases up to the current month')]
class AccrueLeaseCharges extends Command
{
    public function handle(AccrueRecurringCharges $accrue): int
    {
        $count = 0;

        Lease::query()
            ->where('starts_on', '<=', today())
            ->whereHas('apartment')
            ->lazyById()
            ->each(function (Lease $lease) use ($accrue, &$count) {
                $accrue->handle($lease);
                $count++;
            });

        $this->info("Checked {$count} leases.");

        return self::SUCCESS;
    }
}
