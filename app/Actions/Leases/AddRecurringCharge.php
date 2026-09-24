<?php

namespace App\Actions\Leases;

use App\Enums\ChargeType;
use App\Models\Lease;
use App\Models\RecurringCharge;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class AddRecurringCharge
{
    public function __construct(protected AccrueRecurringCharges $accrue) {}

    /**
     * The first month a new charge may start: the current month, or the lease start if it's later.
     */
    public static function earliestMonth(Lease $lease): CarbonImmutable
    {
        return CarbonImmutable::today()->startOfMonth()->max($lease->starts_on->startOfMonth());
    }

    public function handle(Lease $lease, User $user, ChargeType $type, ?string $name, int $amount, CarbonImmutable $validFrom): RecurringCharge
    {
        $validFrom = $validFrom->startOfMonth();

        if ($validFrom->lt(static::earliestMonth($lease))) {
            throw ValidationException::withMessages([
                'validFrom' => __('Nowa opłata może obowiązywać najwcześniej od bieżącego miesiąca.'),
            ]);
        }

        return DB::transaction(function () use ($lease, $user, $type, $name, $amount, $validFrom) {
            $charge = $lease->recurringCharges()->create([
                'type' => $type,
                'name' => $type === ChargeType::Other ? $name : null,
            ]);

            $charge->rates()->create(['amount' => $amount, 'valid_from' => $validFrom, 'created_by' => $user->id]);

            $this->accrue->handle($lease);

            return $charge;
        });
    }
}
