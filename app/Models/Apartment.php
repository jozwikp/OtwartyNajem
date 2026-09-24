<?php

namespace App\Models;

use App\Enums\LeaseStatus;
use App\Support\Countries;
use Carbon\CarbonImmutable;
use Database\Factories\ApartmentFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasManyThrough;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Carbon;
use Illuminate\Support\Number;
use Spatie\Activitylog\Models\Concerns\LogsActivity;
use Spatie\Activitylog\Support\LogOptions;

/**
 * @property int $id
 * @property string $label
 * @property string $street
 * @property string|null $unit_number
 * @property string $postal_code
 * @property string $city
 * @property string $country_code
 * @property string $area
 * @property int|null $created_by
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 * @property Carbon|null $deleted_at
 * @property-read string $address_line
 * @property-read string $full_address
 * @property-read string $country_name
 * @property-read string $area_formatted
 */
#[Fillable(['label', 'street', 'unit_number', 'postal_code', 'city', 'country_code', 'area'])]
class Apartment extends Model
{
    /** @use HasFactory<ApartmentFactory> */
    use HasFactory, LogsActivity, SoftDeletes;

    /**
     * Human readable names of the audited attributes.
     *
     * @return array<string, string>
     */
    public static function attributeLabels(): array
    {
        return [
            'label' => __('Nazwa'),
            'street' => __('Ulica i numer budynku'),
            'unit_number' => __('Numer lokalu'),
            'postal_code' => __('Kod pocztowy'),
            'city' => __('Miasto'),
            'country_code' => __('Kraj'),
            'area' => __('Metraż'),
        ];
    }

    /**
     * Suggest a list label based on the address, e.g. "Puławska 12A/5, Warszawa".
     */
    public static function suggestLabel(string $street, ?string $unitNumber, string $city): string
    {
        $address = trim($street).(filled($unitNumber) ? '/'.trim($unitNumber) : '');

        return trim($address.(filled($city) ? ', '.trim($city) : ''), ' ,');
    }

    protected function casts(): array
    {
        return [
            'area' => 'decimal:2',
        ];
    }

    public function getActivitylogOptions(): LogOptions
    {
        return LogOptions::defaults()
            ->useLogName('apartments')
            ->logOnly(array_keys(self::attributeLabels()))
            ->logOnlyDirty()
            ->dontLogEmptyChanges();
    }

    /**
     * @return BelongsToMany<User, $this>
     */
    public function owners(): BelongsToMany
    {
        return $this->belongsToMany(User::class, 'apartment_owners')
            ->withPivot('added_by')
            ->withTimestamps();
    }

    /**
     * @return HasMany<ApartmentInvitation, $this>
     */
    public function invitations(): HasMany
    {
        return $this->hasMany(ApartmentInvitation::class);
    }

    /**
     * @return HasMany<Lease, $this>
     */
    public function leases(): HasMany
    {
        return $this->hasMany(Lease::class)->orderByDesc('starts_on');
    }

    /**
     * @return HasManyThrough<Bill, Lease, $this>
     */
    public function bills(): HasManyThrough
    {
        return $this->hasManyThrough(Bill::class, Lease::class);
    }

    /**
     * The lease to show by default: the one in progress, otherwise the next one, otherwise the most recent.
     */
    public function mainLease(): ?Lease
    {
        $leases = $this->leases()->get();
        $today = CarbonImmutable::today();

        return $leases->first(fn (Lease $lease) => $lease->status($today) === LeaseStatus::Active)
            ?? $leases->filter(fn (Lease $lease) => $lease->status($today) === LeaseStatus::Upcoming)->sortBy('starts_on')->first()
            ?? $leases->first();
    }

    /**
     * Another lease of this apartment that overlaps the given period (only one lease at a time is allowed).
     */
    public function overlappingLease(CarbonImmutable $startsOn, ?CarbonImmutable $endsOn, ?int $exceptLeaseId = null): ?Lease
    {
        return $this->leases()
            ->when($exceptLeaseId, fn ($query) => $query->whereKeyNot($exceptLeaseId))
            ->get()
            ->first(function (Lease $lease) use ($startsOn, $endsOn) {
                $otherEnd = $lease->effectiveEndsOn();

                return ($endsOn === null || $lease->starts_on->lte($endsOn))
                    && ($otherEnd === null || $otherEnd->gte($startsOn));
            });
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    /**
     * @param  Builder<Apartment>  $query
     */
    public function scopeOwnedBy(Builder $query, User $user): void
    {
        $query->whereHas('owners', fn (Builder $owners) => $owners->whereKey($user->id));
    }

    public function isOwnedBy(?User $user): bool
    {
        return $user !== null && $this->owners()->whereKey($user->id)->exists();
    }

    protected function addressLine(): Attribute
    {
        return Attribute::get(fn () => $this->street.(filled($this->unit_number) ? ' / '.__('lok.').' '.$this->unit_number : ''));
    }

    protected function fullAddress(): Attribute
    {
        return Attribute::get(fn () => $this->address_line.', '.$this->postal_code.' '.$this->city.
            ($this->country_code !== 'PL' ? ', '.$this->country_name : ''));
    }

    protected function countryName(): Attribute
    {
        return Attribute::get(fn () => Countries::name($this->country_code));
    }

    protected function areaFormatted(): Attribute
    {
        return Attribute::get(fn () => Number::format((float) $this->area, maxPrecision: 2, locale: 'pl').' m²');
    }
}
