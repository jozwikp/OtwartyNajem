<?php

namespace App\Actions\Leases;

use App\Enums\ChargeType;
use App\Models\Apartment;
use App\Models\Lease;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

class CreateLease
{
    public function __construct(
        protected EnsureNoOverlap $ensureNoOverlap,
        protected AccrueRecurringCharges $accrue,
    ) {}

    /**
     * @param  array<string, mixed>  $leaseData  lease attributes (dates as Y-m-d, amounts in minor units)
     * @param  list<array{first_name: string, last_name: string, email: ?string, phone: ?string}>  $tenants  the first one is the main contact
     * @param  list<array{type: ChargeType, name: ?string, amount: int}>  $charges
     */
    public function handle(Apartment $apartment, User $user, array $leaseData, array $tenants, array $charges): Lease
    {
        return DB::transaction(function () use ($apartment, $user, $leaseData, $tenants, $charges) {
            $startsOn = CarbonImmutable::parse($leaseData['starts_on']);
            $endsOn = filled($leaseData['ends_on'] ?? null) ? CarbonImmutable::parse($leaseData['ends_on']) : null;

            $this->ensureNoOverlap->handle($apartment, $startsOn, $endsOn);

            $lease = new Lease($leaseData);
            $lease->creator()->associate($user);
            $apartment->leases()->save($lease);

            foreach ($tenants as $index => $tenant) {
                $lease->tenants()->create([...$tenant, 'is_primary' => $index === 0]);
            }

            foreach ($charges as $charge) {
                $recurring = $lease->recurringCharges()->create([
                    'type' => $charge['type'],
                    'name' => $charge['type'] === ChargeType::Other ? $charge['name'] : null,
                ]);
                $recurring->rates()->create([
                    'amount' => $charge['amount'],
                    'valid_from' => $startsOn->startOfMonth(),
                    'created_by' => $user->id,
                ]);
            }

            $this->accrue->handle($lease);

            // Months before the current one are history: the tenant only hears about this month on.
            $lease->ledgerEntries()
                ->where('period', '<', now()->startOfMonth()->toDateString())
                ->get()
                ->each(fn ($entry) => $entry->forceFill(['notified_at' => now(), 'notified_amount' => $entry->amount])->saveQuietly());

            return $lease;
        });
    }
}
