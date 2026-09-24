<?php

namespace App\Models\Concerns;

use Spatie\Activitylog\Models\Concerns\LogsActivity;
use Spatie\Activitylog\Support\LogOptions;

/**
 * Models that belong to an apartment: their changes are logged and appear
 * in the apartment's change history (via the "apartment_id" property).
 */
trait AuditsApartment
{
    use LogsActivity;

    abstract public function auditApartmentId(): ?int;

    /**
     * @return list<string>
     */
    abstract protected function auditedAttributes(): array;

    public function auditCurrency(): ?string
    {
        return null;
    }

    public function getActivitylogOptions(): LogOptions
    {
        return LogOptions::defaults()
            ->useLogName($this->getTable())
            ->logOnly($this->auditedAttributes())
            ->logOnlyDirty()
            ->dontLogEmptyChanges();
    }
}
