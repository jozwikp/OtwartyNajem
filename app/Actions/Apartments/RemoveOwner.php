<?php

namespace App\Actions\Apartments;

use App\Models\Apartment;
use App\Models\User;
use App\Support\Audit;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class RemoveOwner
{
    /**
     * Remove an owner from the apartment. When $owner is the acting user, they leave the apartment.
     */
    public function handle(Apartment $apartment, User $owner, User $actor): void
    {
        DB::transaction(function () use ($apartment, $owner, $actor) {
            if ($apartment->owners()->lockForUpdate()->count() <= 1) {
                throw ValidationException::withMessages([
                    'owner' => __('Mieszkanie musi mieć co najmniej jednego właściciela.'),
                ]);
            }

            $apartment->owners()->detach($owner->id);

            Audit::apartment($apartment, $owner->is($actor) ? 'owner_left' : 'owner_removed', [
                'user_id' => $owner->id,
                'name' => $owner->name,
                'email' => $owner->email,
            ], $actor);
        });
    }
}
