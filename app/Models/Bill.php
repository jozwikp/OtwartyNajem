<?php

namespace App\Models;

use App\Enums\BillCategory;
use App\Models\Concerns\AuditsApartment;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\MorphOne;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * A utility bill (invoice) charged to the tenant after it's uploaded.
 *
 * @property int $id
 * @property int $lease_id
 * @property BillCategory $category
 * @property string|null $supplier
 * @property string|null $invoice_number
 * @property CarbonImmutable $issued_on
 * @property CarbonImmutable $period_from
 * @property CarbonImmutable $period_to
 * @property string $currency
 * @property int $total_amount
 * @property int $tenant_amount
 * @property CarbonImmutable $due_on
 * @property string $file_path
 * @property string $file_name
 * @property-read Lease $lease
 */
#[Fillable([
    'category', 'supplier', 'invoice_number', 'issued_on', 'period_from', 'period_to',
    'currency', 'total_amount', 'tenant_amount', 'due_on', 'file_path', 'file_name', 'created_by',
])]
class Bill extends Model
{
    use AuditsApartment, SoftDeletes;

    protected function casts(): array
    {
        return [
            'category' => BillCategory::class,
            'issued_on' => 'immutable_date',
            'period_from' => 'immutable_date',
            'period_to' => 'immutable_date',
            'due_on' => 'immutable_date',
            'total_amount' => 'integer',
            'tenant_amount' => 'integer',
        ];
    }

    public function auditApartmentId(): ?int
    {
        return $this->lease?->apartment_id;
    }

    public function auditCurrency(): ?string
    {
        return $this->currency;
    }

    protected function auditedAttributes(): array
    {
        return ['category', 'supplier', 'invoice_number', 'issued_on', 'period_from', 'period_to', 'total_amount', 'tenant_amount', 'due_on', 'file_name'];
    }

    /**
     * @return array<string, string>
     */
    public static function attributeLabels(): array
    {
        return [
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
     * @return BelongsTo<Lease, $this>
     */
    public function lease(): BelongsTo
    {
        return $this->belongsTo(Lease::class);
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
        return $this->tenant_amount !== $this->total_amount;
    }
}
