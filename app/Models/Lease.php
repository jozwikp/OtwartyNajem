<?php

namespace App\Models;

use App\Contracts\BelongsToApartment;
use App\Enums\LeaseStatus;
use App\Enums\LedgerKind;
use App\Enums\PaymentMethod;
use App\Models\Concerns\AuditsApartment;
use Carbon\CarbonImmutable;
use Database\Factories\LeaseFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * @property int $id
 * @property int $apartment_id
 * @property CarbonImmutable $starts_on
 * @property CarbonImmutable|null $ends_on
 * @property CarbonImmutable|null $terminated_on
 * @property string $currency
 * @property int $payment_due_day
 * @property string|null $bank_account
 * @property string|null $notes
 * @property bool $notify_tenants
 * @property CarbonImmutable|null $last_notified_at
 * @property int|null $deposit_amount
 * @property PaymentMethod|null $deposit_method
 * @property CarbonImmutable|null $deposit_returned_on
 * @property int|null $deposit_returned_amount
 * @property string|null $deposit_return_notes
 * @property-read Apartment $apartment
 */
#[Fillable([
    'starts_on', 'ends_on', 'terminated_on', 'currency', 'payment_due_day', 'bank_account', 'notes', 'notify_tenants',
    'deposit_amount', 'deposit_method', 'deposit_returned_on', 'deposit_returned_amount', 'deposit_return_notes',
])]
class Lease extends Model implements BelongsToApartment
{
    /** @use HasFactory<LeaseFactory> */
    use AuditsApartment, HasFactory, SoftDeletes;

    protected function casts(): array
    {
        return [
            'starts_on' => 'immutable_date',
            'ends_on' => 'immutable_date',
            'terminated_on' => 'immutable_date',
            'deposit_returned_on' => 'immutable_date',
            'bank_account' => 'encrypted',
            'notes' => 'encrypted',
            'deposit_return_notes' => 'encrypted',
            'payment_due_day' => 'integer',
            'notify_tenants' => 'boolean',
            'last_notified_at' => 'immutable_datetime',
            'deposit_amount' => 'integer',
            'deposit_returned_amount' => 'integer',
            'deposit_method' => PaymentMethod::class,
        ];
    }

    public function auditApartmentId(): ?int
    {
        return $this->apartment_id;
    }

    public function auditCurrency(): ?string
    {
        return $this->currency;
    }

    protected function auditedAttributes(): array
    {
        return array_values($this->getFillable());
    }

    /**
     * @return array<string, string>
     */
    public static function attributeLabels(): array
    {
        return [
            'starts_on' => __('Początek umowy'),
            'ends_on' => __('Koniec umowy'),
            'terminated_on' => __('Faktyczne zakończenie'),
            'currency' => __('Waluta'),
            'payment_due_day' => __('Termin płatności (dzień miesiąca)'),
            'bank_account' => __('Numer konta'),
            'notes' => __('Informacje dodatkowe'),
            'notify_tenants' => __('Powiadomienia e-mail dla najemcy'),
            'deposit_amount' => __('Kaucja'),
            'deposit_method' => __('Sposób wpłaty kaucji'),
            'deposit_returned_on' => __('Data zwrotu kaucji'),
            'deposit_returned_amount' => __('Zwrócona kwota kaucji'),
            'deposit_return_notes' => __('Uwagi do zwrotu kaucji'),
        ];
    }

    /**
     * @return BelongsTo<Apartment, $this>
     */
    public function apartment(): BelongsTo
    {
        return $this->belongsTo(Apartment::class);
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    /**
     * @return HasMany<LeaseTenant, $this>
     */
    public function tenants(): HasMany
    {
        return $this->hasMany(LeaseTenant::class)->orderByDesc('is_primary')->orderBy('id');
    }

    /**
     * @return HasOne<LeaseTenant, $this>
     */
    public function primaryTenant(): HasOne
    {
        return $this->hasOne(LeaseTenant::class)->where('is_primary', true);
    }

    /**
     * @return HasMany<LeasePayer, $this>
     */
    public function payers(): HasMany
    {
        return $this->hasMany(LeasePayer::class);
    }

    /**
     * @return HasMany<RecurringCharge, $this>
     */
    public function recurringCharges(): HasMany
    {
        return $this->hasMany(RecurringCharge::class)->orderBy('id');
    }

    /**
     * @return HasMany<Bill, $this>
     */
    public function bills(): HasMany
    {
        return $this->hasMany(Bill::class);
    }

    /**
     * @return HasMany<LedgerEntry, $this>
     */
    public function ledgerEntries(): HasMany
    {
        return $this->hasMany(LedgerEntry::class);
    }

    /**
     * The day the lease really ends: early termination wins over the contract end date.
     */
    public function effectiveEndsOn(): ?CarbonImmutable
    {
        return $this->terminated_on ?? $this->ends_on;
    }

    public function status(?CarbonImmutable $today = null): LeaseStatus
    {
        $today ??= CarbonImmutable::today();

        if ($this->starts_on->isAfter($today)) {
            return LeaseStatus::Upcoming;
        }

        $end = $this->effectiveEndsOn();

        return $end !== null && $end->isBefore($today) ? LeaseStatus::Ended : LeaseStatus::Active;
    }

    /**
     * Charges minus payments, in minor units. Positive = tenant owes money.
     */
    public function balance(): int
    {
        $sums = $this->ledgerEntries()
            ->selectRaw('kind, sum(amount) as total')
            ->groupBy('kind')
            ->pluck('total', 'kind');

        return (int) ($sums[LedgerKind::Charge->value] ?? 0) - (int) ($sums[LedgerKind::Payment->value] ?? 0);
    }

    public function hasLedgerEntries(): bool
    {
        return $this->ledgerEntries()->exists();
    }

    /**
     * E-mail addresses of the tenants that get the daily summary.
     *
     * @return list<string>
     */
    public function tenantEmails(): array
    {
        return array_values($this->tenants
            ->map(fn (LeaseTenant $tenant): ?string => $tenant->email)
            ->filter(fn (?string $email): bool => filled($email))
            ->unique()
            ->all());
    }

    public function tenantNames(): string
    {
        return $this->tenants->map->full_name->join(', ', ' i ');
    }
}
