<?php

namespace App\Actions\Leases;

use App\Enums\LedgerKind;
use App\Enums\PaymentMethod;
use App\Models\Lease;
use App\Models\LedgerEntry;
use App\Models\User;
use Carbon\CarbonImmutable;

class RecordPayment
{
    public function handle(Lease $lease, User $user, int $amount, CarbonImmutable $paidOn, PaymentMethod $method, ?string $notes = null): LedgerEntry
    {
        $entry = new LedgerEntry([
            'kind' => LedgerKind::Payment,
            'currency' => $lease->currency,
            'amount' => $amount,
            'booked_on' => $paidOn,
            'description' => __('Wpłata – :method', ['method' => mb_strtolower($method->label())]),
            'payment_method' => $method,
            'notes' => $notes,
            'created_by' => $user->id,
        ]);

        $entry->lease()->associate($lease);
        $entry->save();

        return $entry;
    }
}
