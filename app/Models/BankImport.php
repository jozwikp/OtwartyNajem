<?php

namespace App\Models;

use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * @property int $id
 * @property int $user_id
 * @property string $file_name
 * @property string|null $bank
 * @property CarbonImmutable|null $period_from
 * @property CarbonImmutable|null $period_to
 * @property int $rows_total
 * @property int $incoming_total
 * @property int $duplicates
 * @property CarbonImmutable|null $created_at
 */
#[Fillable(['user_id', 'file_name', 'bank', 'period_from', 'period_to', 'rows_total', 'incoming_total', 'duplicates'])]
class BankImport extends Model
{
    protected function casts(): array
    {
        return [
            'period_from' => 'immutable_date',
            'period_to' => 'immutable_date',
        ];
    }

    /**
     * @return HasMany<BankTransaction, $this>
     */
    public function transactions(): HasMany
    {
        return $this->hasMany(BankTransaction::class);
    }
}
