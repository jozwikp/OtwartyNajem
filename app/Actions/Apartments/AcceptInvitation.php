<?php

namespace App\Actions\Apartments;

use App\Models\ApartmentInvitation;
use App\Models\User;
use App\Support\Audit;
use Illuminate\Support\Facades\DB;

class AcceptInvitation
{
    public function handle(ApartmentInvitation $invitation, User $user): void
    {
        DB::transaction(function () use ($invitation, $user) {
            $apartment = $invitation->apartment;

            if (! $apartment->isOwnedBy($user)) {
                $apartment->owners()->attach($user->id, ['added_by' => $invitation->invited_by]);
            }

            $invitation->forceFill([
                'accepted_at' => now(),
                'accepted_by' => $user->id,
            ])->save();

            Audit::apartment($apartment, 'owner_joined', [
                'user_id' => $user->id,
                'name' => $user->name,
                'email' => $user->email,
                'invited_email' => $invitation->email,
            ], $user);
        });
    }
}
