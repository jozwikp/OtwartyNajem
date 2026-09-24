<?php

namespace App\Actions\Apartments;

use App\Models\ApartmentInvitation;
use App\Notifications\ApartmentInvitationNotification;
use App\Support\Audit;
use Illuminate\Support\Facades\Notification;

class ResendInvitation
{
    /**
     * Sends a new link (the previous one stops working) and extends the expiry date.
     */
    public function handle(ApartmentInvitation $invitation): void
    {
        $plainToken = $invitation->refreshToken();
        $invitation->declined_at = null;
        $invitation->save();

        Notification::route('mail', $invitation->email)
            ->notify(new ApartmentInvitationNotification($invitation, $plainToken));

        Audit::apartment($invitation->apartment, 'invitation_resent', ['email' => $invitation->email]);
    }
}
