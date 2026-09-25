<?php

namespace App\Support;

use App\Models\Lease;
use App\Models\LedgerEntry;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * What the tenant hasn't been told yet: new charges, changed amounts and received payments.
 * One digest per lease is e-mailed once a day.
 */
class TenantDigest
{
    /** @var Collection<int, LedgerEntry> */
    public Collection $newCharges;

    /** @var Collection<int, LedgerEntry> */
    public Collection $changedCharges;

    /** @var Collection<int, LedgerEntry> */
    public Collection $payments;

    public LeaseStatement $statement;

    public function __construct(public Lease $lease)
    {
        $lease->loadMissing(['tenants', 'apartment']);

        $entries = $lease->ledgerEntries()->with('source')->orderBy('booked_on')->orderBy('id')->get();

        $this->newCharges = $entries->filter(fn (LedgerEntry $e) => $e->isCharge() && $e->notified_at === null)->values();
        $this->changedCharges = $entries->filter(fn (LedgerEntry $e) => $e->isCharge() && $e->notified_at !== null && $e->notified_amount !== $e->amount)->values();
        $this->payments = $entries->filter(fn (LedgerEntry $e) => $e->isPayment() && $e->notified_at === null)->values();
        $this->statement = new LeaseStatement($lease);
    }

    /**
     * A mail goes out only when something was charged or an amount changed.
     */
    public function hasNews(): bool
    {
        return $this->newCharges->isNotEmpty() || $this->changedCharges->isNotEmpty();
    }

    public function money(int $amount): string
    {
        return Money::format($amount, $this->lease->currency);
    }

    public function subject(): string
    {
        $balance = $this->statement->balance();

        return $balance > 0
            ? __(':label – do zapłaty :amount', ['label' => $this->lease->apartment->label, 'amount' => $this->money($balance)])
            : __(':label – nowe opłaty', ['label' => $this->lease->apartment->label]);
    }

    /**
     * Suggested transfer title.
     */
    public function transferTitle(): string
    {
        return __('Opłaty :label :month', [
            'label' => $this->lease->apartment->label,
            'month' => now()->isoFormat('MM/YYYY'),
        ]);
    }

    /**
     * Remember that everything in this digest has been sent.
     */
    public function markAsSent(): void
    {
        $now = now();
        $ids = collect([...$this->newCharges, ...$this->changedCharges, ...$this->payments])->pluck('id');

        // Update in the database only, so this digest keeps showing what was sent.
        LedgerEntry::whereKey($ids)->update(['notified_at' => $now, 'notified_amount' => DB::raw('amount')]);

        $this->lease->forceFill(['last_notified_at' => $now])->saveQuietly();
    }
}
