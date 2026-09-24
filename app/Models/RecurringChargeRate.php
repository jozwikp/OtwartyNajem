<?php

namespace App\Models;

use App\Models\Concerns\AuditsApartment;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * @property int $id
 * @property int $recurring_charge_id
 * @property int $amount
 * @property CarbonImmutable $valid_from
 * @property-read RecurringCharge $charge
 */
#[Fillable(['amount', 'valid_from', 'created_by'])]
class RecurringChargeRate extends Model
{
    use AuditsApartment;

    protected function casts(): array
    {
        return [
            'amount' => 'integer',
            'valid_from' => 'immutable_date',
        ];
    }

    public function auditApartmentId(): ?int
    {
        return $this->charge?->lease?->apartment_id;
    }

    public function auditCurrency(): ?string
    {
        return $this->charge?->lease?->currency;
    }

    protected function auditedAttributes(): array
    {
        return ['amount', 'valid_from'];
    }

    /**
     * @return BelongsTo<RecurringCharge, $this>
     */
    public function charge(): BelongsTo
    {
        return $this->belongsTo(RecurringCharge::class, 'recurring_charge_id');
    }
}
