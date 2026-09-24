<?php

namespace App\Actions\Apartments;

use App\Models\Apartment;
use App\Models\ApartmentInvitation;
use App\Models\User;
use App\Notifications\ApartmentInvitationNotification;
use App\Support\Audit;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class InviteOwner
{
    public function handle(Apartment $apartment, User $inviter, string $email): ApartmentInvitation
    {
        $email = Str::lower(trim($email));

        if ($apartment->owners()->where('email', $email)->exists()) {
            throw ValidationException::withMessages([
                'email' => __('Ta osoba już jest właścicielem tego mieszkania.'),
            ]);
        }

        if ($apartment->invitations()->pending()->where('email', $email)->exists()) {
            throw ValidationException::withMessages([
                'email' => __('Zaproszenie na ten adres już czeka na odpowiedź. Możesz je wysłać ponownie z listy poniżej.'),
            ]);
        }

        $invitation = new ApartmentInvitation(['email' => $email, 'invited_by' => $inviter->id]);
        $plainToken = $invitation->refreshToken();
        $apartment->invitations()->save($invitation);

        Notification::route('mail', $email)
            ->notify(new ApartmentInvitationNotification($invitation, $plainToken));

        Audit::apartment($apartment, 'owner_invited', ['email' => $email]);

        return $invitation;
    }
}
