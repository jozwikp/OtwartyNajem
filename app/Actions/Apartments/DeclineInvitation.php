<?php

namespace App\Actions\Apartments;

use App\Models\ApartmentInvitation;
use App\Models\User;
use App\Support\Audit;

class DeclineInvitation
{
    public function handle(ApartmentInvitation $invitation, ?User $user = null): void
    {
        $invitation->forceFill(['declined_at' => now()])->save();

        Audit::apartment($invitation->apartment, 'invitation_declined', ['email' => $invitation->email], $user);
    }
}
