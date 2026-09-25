<?php

namespace App\Models;

use App\Contracts\BelongsToApartment;
use App\Enums\BankTransactionStatus;
use App\Enums\LedgerKind;
use App\Enums\PaymentMethod;
use App\Models\Concerns\AuditsApartment;
use Carbon\CarbonImmutable;
use DomainException;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\MorphTo;

/**
 * One line of the settlement: a charge (naliczenie) or a payment (wpłata).
 *
 * @property int $id
 * @property int $lease_id
 * @property LedgerKind $kind
 * @property string $currency
 * @property int $amount
 * @property CarbonImmutable $booked_on
 * @property CarbonImmutable|null $due_on
 * @property CarbonImmutable|null $period
 * @property string $description
 * @property string|null $source_type
 * @property int|null $source_id
 * @property PaymentMethod|null $payment_method
 * @property string|null $notes
 * @property CarbonImmutable|null $notified_at
 * @property int|null $notified_amount
 * @property-read Lease $lease
 */
#[Fillable([
    'kind', 'currency', 'amount', 'booked_on', 'due_on', 'period', 'description',
    'payment_method', 'notes', 'created_by',
])]
class LedgerEntry extends Model implements BelongsToApartment
{
    use AuditsApartment;

    protected static function booted(): void
    {
        // Every settlement line must be in the lease's currency.
        static::saving(function (LedgerEntry $entry) {
            $leaseCurrency = $entry->lease()->withTrashed()->value('currency');

            if ($entry->currency !== $leaseCurrency) {
                throw new DomainException("Ledger entry currency {$entry->currency} does not match lease currency {$leaseCurrency}.");
            }
        });

        static::deleted(function (LedgerEntry $entry) {
            if ($entry->source_type === (new BankTransaction)->getMorphClass()) {
                BankTransaction::whereKey($entry->source_id)->update([
                    'status' => BankTransactionStatus::Review,
                    'decided_by' => null,
                    'decided_at' => null,
                ]);
            }
        });
    }

    protected function casts(): array
    {
        return [
            'kind' => LedgerKind::class,
            'payment_method' => PaymentMethod::class,
            'amount' => 'integer',
            'notes' => 'encrypted',
            'booked_on' => 'immutable_date',
            'due_on' => 'immutable_date',
            'period' => 'immutable_date',
            'notified_at' => 'immutable_datetime',
            'notified_amount' => 'integer',
        ];
    }

    public function auditApartmentId(): ?int
    {
        return $this->lease->apartment_id;
    }

    public function auditCurrency(): ?string
    {
        return $this->currency;
    }

    protected function auditedAttributes(): array
    {
        return ['kind', 'amount', 'booked_on', 'due_on', 'description', 'payment_method', 'notes'];
    }

    /**
     * @return BelongsTo<Lease, $this>
     */
    public function lease(): BelongsTo
    {
        return $this->belongsTo(Lease::class);
    }

    /**
     * @return MorphTo<Model, $this>
     */
    public function source(): MorphTo
    {
        return $this->morphTo();
    }

    public function isCharge(): bool
    {
        return $this->kind === LedgerKind::Charge;
    }

    public function isPayment(): bool
    {
        return $this->kind === LedgerKind::Payment;
    }
}
