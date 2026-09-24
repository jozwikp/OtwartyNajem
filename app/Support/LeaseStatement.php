<?php

namespace App\Support;

use App\Models\Lease;
use App\Models\LedgerEntry;
use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;

/**
 * The settlement of a lease: one balance, where payments always cover the oldest charges first.
 */
class LeaseStatement
{
    /** @var Collection<int, LedgerEntry> */
    public Collection $charges;

    /** @var Collection<int, LedgerEntry> */
    public Collection $payments;

    /**
     * How much of each charge is paid, by ledger entry id.
     *
     * @var array<int, int>
     */
    public array $paid = [];

    public int $totalCharged = 0;

    public int $totalPaid = 0;

    public function __construct(public Lease $lease, public ?CarbonImmutable $today = null)
    {
        $this->today ??= CarbonImmutable::today();

        $entries = $lease->ledgerEntries()->with('source')->get();

        $this->charges = $entries->filter->isCharge()
            ->sortBy([
                fn (LedgerEntry $a, LedgerEntry $b) => ($a->due_on ?? $a->booked_on) <=> ($b->due_on ?? $b->booked_on),
                fn (LedgerEntry $a, LedgerEntry $b) => $a->booked_on <=> $b->booked_on,
                fn (LedgerEntry $a, LedgerEntry $b) => $a->id <=> $b->id,
            ])
            ->values();

        $this->payments = $entries->filter->isPayment()->sortBy('booked_on')->values();

        $this->totalCharged = (int) $this->charges->sum('amount');
        $this->totalPaid = (int) $this->payments->sum('amount');

        $available = $this->totalPaid;
        foreach ($this->charges as $charge) {
            $this->paid[$charge->id] = min($available, $charge->amount);
            $available -= $this->paid[$charge->id];
        }
    }

    /**
     * Positive: the tenant owes money. Negative: overpayment.
     */
    public function balance(): int
    {
        return $this->totalCharged - $this->totalPaid;
    }

    public function paidFor(LedgerEntry $charge): int
    {
        return $this->paid[$charge->id] ?? 0;
    }

    public function remainingFor(LedgerEntry $charge): int
    {
        return $charge->amount - $this->paidFor($charge);
    }

    /**
     * paid | partial | overdue | due
     */
    public function statusOf(LedgerEntry $charge): string
    {
        $remaining = $this->remainingFor($charge);

        return match (true) {
            $remaining === 0 => 'paid',
            $charge->due_on !== null && $charge->due_on->lt($this->today) => 'overdue',
            $remaining < $charge->amount => 'partial',
            default => 'due',
        };
    }

    /**
     * Unpaid amount that is already past its due date.
     */
    public function overdueAmount(): int
    {
        return (int) $this->charges
            ->filter(fn (LedgerEntry $charge) => $this->statusOf($charge) === 'overdue')
            ->sum(fn (LedgerEntry $charge) => $this->remainingFor($charge));
    }

    public function nextDueCharge(): ?LedgerEntry
    {
        return $this->charges->first(fn (LedgerEntry $charge) => $this->remainingFor($charge) > 0 && $this->statusOf($charge) !== 'overdue');
    }

    /**
     * Charges and payments grouped by month, newest month first.
     *
     * @return Collection<string, array{month: CarbonImmutable, charges: Collection<int, LedgerEntry>, payments: Collection<int, LedgerEntry>, charged: int, paid: int, open: int}>
     */
    public function months(): Collection
    {
        $keyOf = fn (LedgerEntry $entry) => ($entry->period ?? $entry->booked_on)->format('Y-m');

        $keys = $this->charges->map($keyOf)->merge($this->payments->map($keyOf))->unique()->sortDesc();

        return $keys->mapWithKeys(function (string $key) use ($keyOf) {
            $charges = $this->charges->filter(fn ($entry) => $keyOf($entry) === $key)->values();
            $payments = $this->payments->filter(fn ($entry) => $keyOf($entry) === $key)->values();

            return [$key => [
                'month' => CarbonImmutable::parse($key.'-01'),
                'charges' => $charges,
                'payments' => $payments,
                'charged' => (int) $charges->sum('amount'),
                'paid' => (int) $payments->sum('amount'),
                'open' => (int) $charges->sum(fn ($charge) => $this->remainingFor($charge)),
            ]];
        });
    }
}
