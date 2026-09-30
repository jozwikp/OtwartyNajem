<?php

namespace App\Listeners;

use App\Models\User;
use App\Notifications\NewUserRegisteredNotification;
use Illuminate\Auth\Events\Registered;
use Illuminate\Support\Facades\Notification;

class NotifyAdminOfNewUser
{
    public function handle(Registered $event): void
    {
        $adminEmail = config('app.admin_email');

        if (! $adminEmail || ! $event->user instanceof User) {
            return;
        }

        Notification::route('mail', $adminEmail)->notify(
            (new NewUserRegisteredNotification($event->user))->locale(config('app.locale')),
        );
    }
}
