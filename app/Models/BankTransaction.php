<?php

namespace App\Models;

use App\Enums\BankTransactionStatus;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\MorphOne;

/**
 * An incoming transfer read from a bank statement.
 *
 * @property int $id
 * @property int $bank_import_id
 * @property int $user_id
 * @property string $fingerprint
 * @property CarbonImmutable $booked_on
 * @property int $amount
 * @property string $currency
 * @property string $description
 * @property string|null $sender_name
 * @property string|null $sender_account
 * @property string|null $title
 * @property BankTransactionStatus $status
 * @property int|null $lease_id
 * @property string|null $confidence
 * @property string|null $match_reason
 * @property-read Lease|null $lease
 * @property-read BankImport $import
 * @property-read LedgerEntry|null $payment
 */
#[Fillable([
    'user_id', 'fingerprint', 'booked_on', 'amount', 'currency', 'description', 'sender_name',
    'sender_account', 'title', 'status', 'lease_id', 'confidence', 'match_reason',
])]
class BankTransaction extends Model
{
    protected function casts(): array
    {
        return [
            'booked_on' => 'immutable_date',
            'amount' => 'integer',
            'description' => 'encrypted',
            'sender_name' => 'encrypted',
            'sender_account' => 'encrypted',
            'title' => 'encrypted',
            'status' => BankTransactionStatus::class,
            'decided_at' => 'immutable_datetime',
        ];
    }

    /**
     * @return BelongsTo<BankImport, $this>
     */
    public function import(): BelongsTo
    {
        return $this->belongsTo(BankImport::class, 'bank_import_id');
    }

    /**
     * @return BelongsTo<Lease, $this>
     */
    public function lease(): BelongsTo
    {
        return $this->belongsTo(Lease::class);
    }

    /**
     * The payment booked from this transfer.
     *
     * @return MorphOne<LedgerEntry, $this>
     */
    public function payment(): MorphOne
    {
        return $this->morphOne(LedgerEntry::class, 'source');
    }
}
