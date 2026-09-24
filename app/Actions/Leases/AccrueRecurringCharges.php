<?php

namespace App\Actions\Leases;

use App\Enums\LedgerKind;
use App\Models\Lease;
use App\Models\LedgerEntry;
use App\Models\RecurringCharge;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

/**
 * Charges fixed monthly fees for every month from the start of the lease up to the
 * current month (1st to 1st). The first and last month are charged proportionally
 * to the number of days. Safe to run any number of times: existing charges are
 * only corrected (e.g. after the end date changed), never duplicated.
 */
class AccrueRecurringCharges
{
    public function handle(Lease $lease, ?CarbonImmutable $today = null): void
    {
        $today ??= CarbonImmutable::today();

        DB::transaction(function () use ($lease, $today) {
            $lease->load('recurringCharges.rates');

            foreach ($lease->recurringCharges as $charge) {
                $this->accrueCharge($lease, $charge, $today);
            }
        });
    }

    protected function accrueCharge(Lease $lease, RecurringCharge $charge, CarbonImmutable $today): void
    {
        $existing = $charge->ledgerEntries()->get()->keyBy(fn (LedgerEntry $entry) => $entry->period->format('Y-m'));

        $lastMonth = $today->startOfMonth();
        $end = $lease->effectiveEndsOn();
        if ($end && $end->startOfMonth()->lt($lastMonth)) {
            $lastMonth = $end->startOfMonth();
        }

        $expected = [];
        for ($month = $lease->starts_on->startOfMonth(); $month->lte($lastMonth); $month = $month->addMonth()) {
            if ($line = $this->lineFor($lease, $charge, $month)) {
                $expected[$month->format('Y-m')] = $line;
            }
        }

        foreach ($expected as $key => $line) {
            $entry = $existing->get($key);

            if (! $entry) {
                $entry = new LedgerEntry([
                    'kind' => LedgerKind::Charge,
                    'currency' => $lease->currency,
                    'period' => $line['period'],
                ]);
                $entry->lease()->associate($lease);
                $entry->source()->associate($charge);
            }

            $entry->fill([
                'amount' => $line['amount'],
                'booked_on' => $line['booked_on'],
                'due_on' => $line['due_on'],
                'description' => $line['description'],
            ]);

            if (! $entry->exists || $entry->isDirty()) {
                $entry->save();
            }
        }

        // Months that should no longer be charged (e.g. the lease was ended earlier).
        foreach ($existing->reject(fn (LedgerEntry $entry, string $key) => isset($expected[$key])) as $entry) {
            $entry->delete();
        }
    }

    /**
     * @return array{period: CarbonImmutable, amount: int, booked_on: CarbonImmutable, due_on: CarbonImmutable, description: string}|null
     */
    public function lineFor(Lease $lease, RecurringCharge $charge, CarbonImmutable $month): ?array
    {
        $rate = $charge->rateFor($month);
        if (! $rate || $rate->amount === 0) {
            return null;
        }

        $monthStart = $month->startOfMonth();
        $monthEnd = $month->endOfMonth()->startOfDay();
        $from = $lease->starts_on->max($monthStart);
        $to = ($lease->effectiveEndsOn() ?? $monthEnd)->min($monthEnd);

        if ($from->gt($to)) {
            return null;
        }

        $daysInMonth = $monthStart->daysInMonth;
        $days = (int) $from->diffInDays($to) + 1;
        $amount = $days === $daysInMonth ? $rate->amount : (int) round($rate->amount * $days / $daysInMonth);

        $dueOn = $monthStart->setDay(min($lease->payment_due_day, $daysInMonth))->max($from);

        $description = $charge->label.' – '.$monthStart->locale('pl')->isoFormat('MMMM YYYY');
        if ($days !== $daysInMonth) {
            $description .= ' '.__('(za :days z :total dni)', ['days' => $days, 'total' => $daysInMonth]);
        }

        return [
            'period' => $monthStart,
            'amount' => $amount,
            'booked_on' => $from,
            'due_on' => $dueOn,
            'description' => $description,
        ];
    }
}
