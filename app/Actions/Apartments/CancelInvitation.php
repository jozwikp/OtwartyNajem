<?php

namespace App\Actions\Apartments;

use App\Models\ApartmentInvitation;
use App\Support\Audit;

class CancelInvitation
{
    public function handle(ApartmentInvitation $invitation): void
    {
        $invitation->delete();

        Audit::apartment($invitation->apartment, 'invitation_cancelled', ['email' => $invitation->email]);
    }
}
