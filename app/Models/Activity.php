<?php

namespace App\Models;

use Spatie\Activitylog\Models\Activity as BaseActivity;

/**
 * Change history entry. The details (old and new values, e-mail addresses…) are encrypted;
 * the apartment id is kept in its own column so the history can be filtered by apartment.
 *
 * @property int|null $apartment_id
 */
class Activity extends BaseActivity
{
    protected function casts(): array
    {
        return [
            'attribute_changes' => 'encrypted:collection',
            'properties' => 'encrypted:collection',
        ];
    }
}
