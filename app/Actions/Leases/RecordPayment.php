<?php

namespace App\Actions\Leases;

use App\Enums\LedgerKind;
use App\Enums\PaymentMethod;
use App\Models\Lease;
use App\Models\LedgerEntry;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Model;

class RecordPayment
{
    public function handle(Lease $lease, User $user, int $amount, CarbonImmutable $paidOn, PaymentMethod $method, ?string $notes = null, ?Model $source = null): LedgerEntry
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

        if ($source) {
            $entry->source()->associate($source);
            $entry->description = __('Wpłata – przelew z wyciągu bankowego');
        }

        $entry->save();

        return $entry;
    }
}
