<?php

namespace App\Models;

use App\Contracts\BelongsToApartment;
use App\Enums\BillCategory;
use App\Enums\BillStatus;
use App\Models\Concerns\AuditsApartment;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\MorphOne;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * A utility bill (invoice). It's uploaded first, read by AI, then approved by an owner –
 * only then the tenant is charged.
 *
 * @property int $id
 * @property int|null $apartment_id
 * @property int|null $lease_id
 * @property BillStatus $status
 * @property BillCategory|null $category
 * @property string|null $supplier
 * @property string|null $invoice_number
 * @property CarbonImmutable|null $issued_on
 * @property CarbonImmutable|null $period_from
 * @property CarbonImmutable|null $period_to
 * @property string|null $currency
 * @property int|null $total_amount
 * @property int|null $tenant_amount
 * @property CarbonImmutable|null $due_on
 * @property string $file_path
 * @property string $file_name
 * @property string|null $file_mime
 * @property array<string, mixed>|null $ai_result
 * @property string|null $ai_error
 * @property int|null $created_by
 * @property CarbonImmutable|null $processed_at
 * @property CarbonImmutable|null $approved_at
 * @property-read Apartment|null $apartment
 * @property-read Lease|null $lease
 */
#[Fillable([
    'status', 'category', 'supplier', 'invoice_number', 'issued_on', 'period_from', 'period_to',
    'currency', 'total_amount', 'tenant_amount', 'due_on', 'file_path', 'file_name', 'file_mime', 'created_by',
])]
class Bill extends Model implements BelongsToApartment
{
    use AuditsApartment, SoftDeletes;

    protected function casts(): array
    {
        return [
            'status' => BillStatus::class,
            'category' => BillCategory::class,
            'issued_on' => 'immutable_date',
            'period_from' => 'immutable_date',
            'period_to' => 'immutable_date',
            'due_on' => 'immutable_date',
            'total_amount' => 'integer',
            'tenant_amount' => 'integer',
            'ai_result' => 'encrypted:array',
            'processed_at' => 'immutable_datetime',
            'approved_at' => 'immutable_datetime',
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
        return ['apartment_id', 'status', 'category', 'supplier', 'invoice_number', 'issued_on', 'period_from', 'period_to', 'total_amount', 'tenant_amount', 'due_on', 'file_name'];
    }

    /**
     * @return array<string, string>
     */
    public static function attributeLabels(): array
    {
        return [
            'apartment_id' => __('Mieszkanie'),
            'status' => __('Status'),
            'category' => __('Rodzaj'),
            'supplier' => __('Dostawca'),
            'invoice_number' => __('Numer faktury'),
            'issued_on' => __('Data wystawienia'),
            'period_from' => __('Okres od'),
            'period_to' => __('Okres do'),
            'total_amount' => __('Kwota faktury'),
            'tenant_amount' => __('Kwota dla najemcy'),
            'due_on' => __('Termin płatności'),
            'file_name' => __('Plik'),
        ];
    }

    /**
     * Bills the user may see: in apartments they own, or uploaded by them and not matched yet.
     *
     * @param  Builder<Bill>  $query
     */
    public function scopeVisibleTo(Builder $query, User $user): void
    {
        $query->where(fn (Builder $q) => $q
            ->whereIn('apartment_id', $user->apartments()->select('apartments.id'))
            ->orWhere(fn (Builder $q) => $q->whereNull('apartment_id')->where('created_by', $user->id)));
    }

    /**
     * @return BelongsTo<Apartment, $this>
     */
    public function apartment(): BelongsTo
    {
        return $this->belongsTo(Apartment::class);
    }

    /**
     * @return BelongsTo<Lease, $this>
     */
    public function lease(): BelongsTo
    {
        return $this->belongsTo(Lease::class);
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function uploader(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    /**
     * @return MorphOne<LedgerEntry, $this>
     */
    public function ledgerEntry(): MorphOne
    {
        return $this->morphOne(LedgerEntry::class, 'source');
    }

    public function isPartial(): bool
    {
        return $this->tenant_amount !== null && $this->tenant_amount !== $this->total_amount;
    }

    public function isImage(): bool
    {
        return str_starts_with((string) $this->file_mime, 'image/');
    }

    /**
     * What the AI said about the match, e.g. the address found on the invoice.
     */
    public function aiValue(string $key): mixed
    {
        return $this->ai_result[$key] ?? null;
    }

    /**
     * Reasons why the bill can't be approved yet (empty = ready).
     *
     * @return list<string>
     */
    public function missingForApproval(): array
    {
        $missing = [];

        if (! $this->apartment_id) {
            $missing[] = __('Wybierz mieszkanie.');
        }
        if ($this->category === null) {
            $missing[] = __('Wybierz rodzaj rachunku.');
        }
        if ($this->total_amount === null || $this->tenant_amount === null) {
            $missing[] = __('Podaj kwotę.');
        }
        if (! $this->issued_on || ! $this->period_from || ! $this->period_to || ! $this->due_on) {
            $missing[] = __('Uzupełnij daty (okres i termin płatności).');
        }
        if ($this->lease && $this->currency && $this->currency !== $this->lease->currency) {
            $missing[] = __('Faktura jest w :bill, a najem rozliczany w :lease.', ['bill' => $this->currency, 'lease' => $this->lease->currency]);
        }

        return $missing;
    }
}
