<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A bank account the rent for a lease was paid from (e.g. the tenant's parent).
 * The account is encrypted; a keyed hash of it is used to look it up.
 *
 * @property int $id
 * @property int $lease_id
 * @property string $account
 * @property string $account_hash
 * @property string|null $name
 */
#[Fillable(['account', 'name'])]
class LeasePayer extends Model
{
    protected static function booted(): void
    {
        static::saving(function (LeasePayer $payer) {
            $payer->account_hash = static::hashAccount($payer->account);
        });
    }

    protected function casts(): array
    {
        return [
            'account' => 'encrypted',
            'name' => 'encrypted',
        ];
    }

    public static function hashAccount(string $account): string
    {
        return hash_hmac('sha256', $account, (string) config('app.key'));
    }

    /**
     * @param  Builder<LeasePayer>  $query
     */
    public function scopeForAccount(Builder $query, string $account): void
    {
        $query->where('account_hash', static::hashAccount($account));
    }

    /**
     * @return BelongsTo<Lease, $this>
     */
    public function lease(): BelongsTo
    {
        return $this->belongsTo(Lease::class);
    }
}
