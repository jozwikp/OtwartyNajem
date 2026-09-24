<?php

namespace App\Actions\Bills;

use App\Enums\BillStatus;
use App\Enums\LedgerKind;
use App\Models\Bill;
use App\Models\Lease;
use App\Models\LedgerEntry;
use Carbon\CarbonImmutable;

/**
 * Keeps the tenant's charge in line with the bill: an approved bill with a lease
 * and an amount has exactly one charge; otherwise it has none.
 */
class SyncBillCharge
{
    /**
     * The lease that covers the bill's period (the end of the period decides, then the issue date).
     */
    public static function leaseFor(Bill $bill): ?Lease
    {
        if (! $bill->apartment_id) {
            return null;
        }

        $leases = Lease::where('apartment_id', $bill->apartment_id)->orderByDesc('starts_on')->get();

        foreach (array_filter([$bill->period_to, $bill->period_from, $bill->issued_on]) as $day) {
            $lease = $leases->first(fn (Lease $lease) => $lease->starts_on->lte($day)
                && ($lease->effectiveEndsOn() === null || $lease->effectiveEndsOn()->gte($day)));

            if ($lease) {
                return $lease;
            }
        }

        return null;
    }

    public function handle(Bill $bill): void
    {
        $entry = $bill->ledgerEntry()->first();
        $lease = $bill->lease;

        if ($bill->status !== BillStatus::Approved || ! $lease || (int) $bill->tenant_amount <= 0) {
            $entry?->delete();

            return;
        }

        $entry ??= new LedgerEntry(['kind' => LedgerKind::Charge]);
        $entry->lease()->associate($lease);
        $entry->source()->associate($bill);
        $entry->fill([
            'currency' => $lease->currency,
            'amount' => $bill->tenant_amount,
            'booked_on' => $bill->issued_on ?? CarbonImmutable::today(),
            'due_on' => $bill->due_on,
            'description' => static::describe($bill),
            'created_by' => $entry->created_by ?? $bill->approved_by ?? null,
        ]);

        if (! $entry->exists || $entry->isDirty()) {
            $entry->save();
        }
    }

    public static function describe(Bill $bill): string
    {
        return __('Rachunek: :category', ['category' => $bill->category?->label() ?? __('inny')])
            .($bill->supplier ? ' ('.$bill->supplier.')' : '')
            .($bill->period_from && $bill->period_to ? ' – '.$bill->period_from->format('d.m').'–'.$bill->period_to->format('d.m.Y') : '');
    }
}
