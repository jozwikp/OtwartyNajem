<?php

namespace App\Support;

use App\Models\Apartment;
use App\Models\User;

class Audit
{
    /**
     * Record a custom event in the apartment's change history.
     *
     * @param  array<string, mixed>  $properties
     */
    public static function apartment(Apartment $apartment, string $event, array $properties = [], ?User $causer = null): void
    {
        $logger = activity('apartments')
            ->performedOn($apartment)
            ->event($event)
            ->withProperties($properties);

        if ($causer) {
            $logger->causedBy($causer);
        }

        $logger->log($event);
    }

    /**
     * Record a custom event related to a user account.
     *
     * @param  array<string, mixed>  $properties
     */
    public static function user(User $user, string $event, array $properties = []): void
    {
        activity('users')
            ->performedOn($user)
            ->causedBy($user)
            ->event($event)
            ->withProperties($properties)
            ->log($event);
    }
}
