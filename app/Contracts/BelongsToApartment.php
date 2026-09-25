<?php

namespace App\Contracts;

/**
 * A model whose changes belong in an apartment's change history.
 */
interface BelongsToApartment
{
    public function auditApartmentId(): ?int;

    public function auditCurrency(): ?string;
}
