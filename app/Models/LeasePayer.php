<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A bank account the rent for a lease was paid from (e.g. the tenant's parent).
 *
 * @property int $id
 * @property int $lease_id
 * @property string $account
 * @property string|null $name
 */
#[Fillable(['account', 'name'])]
class LeasePayer extends Model
{
    /**
     * @return BelongsTo<Lease, $this>
     */
    public function lease(): BelongsTo
    {
        return $this->belongsTo(Lease::class);
    }
}
