<?php

namespace App\Models;

use App\Enums\ChargeType;
use App\Models\Concerns\AuditsApartment;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\MorphMany;

/**
 * A fixed monthly charge (rent, administration fee, other) with amounts that can change over time.
 *
 * @property int $id
 * @property int $lease_id
 * @property ChargeType $type
 * @property string|null $name
 * @property-read string $label
 * @property-read Lease $lease
 */
#[Fillable(['type', 'name'])]
class RecurringCharge extends Model
{
    use AuditsApartment;

    protected function casts(): array
    {
        return ['type' => ChargeType::class];
    }

    public function auditApartmentId(): ?int
    {
        return $this->lease?->apartment_id;
    }

    public function auditCurrency(): ?string
    {
        return $this->lease?->currency;
    }

    protected function auditedAttributes(): array
    {
        return ['type', 'name'];
    }

    /**
     * @return BelongsTo<Lease, $this>
     */
    public function lease(): BelongsTo
    {
        return $this->belongsTo(Lease::class);
    }

    /**
     * @return HasMany<RecurringChargeRate, $this>
     */
    public function rates(): HasMany
    {
        return $this->hasMany(RecurringChargeRate::class)->orderBy('valid_from');
    }

    /**
     * @return MorphMany<LedgerEntry, $this>
     */
    public function ledgerEntries(): MorphMany
    {
        return $this->morphMany(LedgerEntry::class, 'source');
    }

    protected function label(): Attribute
    {
        return Attribute::get(fn () => $this->type === ChargeType::Other && filled($this->name)
            ? $this->name
            : $this->type->label());
    }

    /**
     * The rate that applies to the given month (the latest one that started on or before it).
     */
    public function rateFor(CarbonImmutable $month): ?RecurringChargeRate
    {
        return $this->rates
            ->filter(fn (RecurringChargeRate $rate) => $rate->valid_from->lte($month->startOfMonth()))
            ->last();
    }

    /**
     * The last month that has already been charged for this item, if any.
     */
    public function lastAccruedPeriod(): ?CarbonImmutable
    {
        $period = $this->ledgerEntries()->max('period');

        return $period ? CarbonImmutable::parse($period)->startOfMonth() : null;
    }

    /**
     * New amounts may only start in a month that has not been charged yet (no changes backwards).
     */
    public function earliestChangeMonth(): CarbonImmutable
    {
        $earliest = CarbonImmutable::today()->startOfMonth();
        $lastAccrued = $this->lastAccruedPeriod();

        if ($lastAccrued && $lastAccrued->gte($earliest)) {
            $earliest = $lastAccrued->addMonth();
        }

        return $earliest;
    }
}
