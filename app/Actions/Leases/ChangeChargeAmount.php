<?php

namespace App\Actions\Leases;

use App\Models\RecurringCharge;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Sets a new amount from a given month on (e.g. a rent increase). An amount of 0
 * stops the charge from that month. Months already charged are never changed.
 */
class ChangeChargeAmount
{
    public function __construct(protected AccrueRecurringCharges $accrue) {}

    public static function earliestMonth(RecurringCharge $charge): CarbonImmutable
    {
        return $charge->earliestChangeMonth()->max($charge->lease->starts_on->startOfMonth());
    }

    public function handle(RecurringCharge $charge, User $user, int $amount, CarbonImmutable $validFrom): void
    {
        $validFrom = $validFrom->startOfMonth();
        $earliest = static::earliestMonth($charge);

        if ($validFrom->lt($earliest)) {
            throw ValidationException::withMessages([
                'validFrom' => __('Kwotę można zmienić najwcześniej od :month (wcześniejsze miesiące są już naliczone).', [
                    'month' => $earliest->isoFormat('D MMMM YYYY'),
                ]),
            ]);
        }

        DB::transaction(function () use ($charge, $user, $amount, $validFrom) {
            $charge->rates()->updateOrCreate(
                ['valid_from' => $validFrom],
                ['amount' => $amount, 'created_by' => $user->id],
            );

            $this->accrue->handle($charge->lease);
        });
    }
}
