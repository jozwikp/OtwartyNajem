<?php

namespace App\Policies;

use App\Models\Apartment;
use App\Models\User;

/**
 * All owners of an apartment have the same rights.
 */
class ApartmentPolicy
{
    public function view(User $user, Apartment $apartment): bool
    {
        return $apartment->isOwnedBy($user);
    }

    public function update(User $user, Apartment $apartment): bool
    {
        return $apartment->isOwnedBy($user);
    }

    public function delete(User $user, Apartment $apartment): bool
    {
        return $apartment->isOwnedBy($user);
    }

    public function manageOwners(User $user, Apartment $apartment): bool
    {
        return $apartment->isOwnedBy($user);
    }
}
