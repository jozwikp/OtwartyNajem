<?php

namespace App\Actions\Leases;

use App\Models\Lease;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class UpdateLease
{
    public function __construct(
        protected EnsureNoOverlap $ensureNoOverlap,
        protected AccrueRecurringCharges $accrue,
    ) {}

    /**
     * @param  array<string, mixed>  $data
     */
    public function handle(Lease $lease, array $data): void
    {
        DB::transaction(function () use ($lease, $data) {
            $lease->fill($data);

            if ($lease->isDirty(['starts_on', 'currency']) && $lease->hasLedgerEntries()) {
                throw ValidationException::withMessages([
                    'starts_on' => __('Początku umowy i waluty nie można już zmienić, bo są naliczone opłaty lub wpłaty.'),
                ]);
            }

            if ($lease->effectiveEndsOn()?->lt($lease->starts_on)) {
                throw ValidationException::withMessages([
                    $lease->isDirty('terminated_on') ? 'endsOn' : 'ends_on' => __('Koniec najmu nie może być wcześniej niż jego początek.'),
                ]);
            }

            $this->ensureNoOverlap->handle($lease->apartment, $lease->starts_on, $lease->effectiveEndsOn(), $lease->id, 'ends_on');

            $turnedOnNotifications = $lease->isDirty('notify_tenants') && $lease->notify_tenants;

            $lease->save();

            $this->accrue->handle($lease);

            if ($turnedOnNotifications) {
                // Start fresh: the tenant only hears about what happens from now on.
                $lease->ledgerEntries()->whereNull('notified_at')->get()
                    ->each(fn ($entry) => $entry->forceFill(['notified_at' => now(), 'notified_amount' => $entry->amount])->saveQuietly());
            }
        });
    }
}
