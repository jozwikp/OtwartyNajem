<?php

namespace App\Notifications;

use App\Models\User;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

/**
 * Tells the administrator someone has just created an account. Queued, so a slow or failing
 * mail server never gets in the way of signing up.
 */
class NewUserRegisteredNotification extends Notification implements ShouldQueue
{
    use Queueable;

    public function __construct(public User $user) {}

    /**
     * @return array<int, string>
     */
    public function via(object $notifiable): array
    {
        return ['mail'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        return (new MailMessage)
            ->subject(__('Nowe konto: :name', ['name' => $this->user->name]))
            ->greeting(__('Nowe konto w :app', ['app' => config('app.name')]))
            ->line(__('Imię i nazwisko: :name', ['name' => $this->user->name]))
            ->line(__('E-mail: :email', ['email' => $this->user->email]))
            ->line(__('Język: :locale', ['locale' => $this->user->locale]))
            ->line(__('Data: :date', ['date' => $this->user->created_at?->translatedFormat('j F Y, H:i')]))
            ->line(__('Wszystkich kont: :count', ['count' => User::count()]));
    }
}
