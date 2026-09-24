<?php

namespace App\Policies;

use App\Models\Bill;
use App\Models\User;

/**
 * Owners of the apartment manage its bills. A bill that isn't matched to an apartment yet
 * belongs to the person who uploaded it.
 */
class BillPolicy
{
    public function view(User $user, Bill $bill): bool
    {
        return $bill->apartment_id
            ? $bill->apartment?->isOwnedBy($user) ?? false
            : $bill->created_by === $user->id;
    }

    public function update(User $user, Bill $bill): bool
    {
        return $this->view($user, $bill);
    }

    public function delete(User $user, Bill $bill): bool
    {
        return $this->view($user, $bill);
    }
}
