<?php

namespace App\Notifications;

use App\Models\ApartmentInvitation;
use Illuminate\Bus\Queueable;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

class ApartmentInvitationNotification extends Notification
{
    use Queueable;

    public function __construct(
        public ApartmentInvitation $invitation,
        public string $plainToken,
    ) {}

    /**
     * @return array<int, string>
     */
    public function via(object $notifiable): array
    {
        return ['mail'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        $apartment = $this->invitation->apartment;
        $inviter = $this->invitation->inviter?->name ?? __('Współwłaściciel');

        return (new MailMessage)
            ->subject(__('Zaproszenie do mieszkania :label', ['label' => $apartment->label]))
            ->greeting(__('Dzień dobry!'))
            ->line(__(':name zaprasza Cię do wspólnego zarządzania mieszkaniem:', ['name' => $inviter]))
            ->line('**'.$apartment->label.'**  '.PHP_EOL.$apartment->full_address)
            ->action(__('Zobacz zaproszenie'), route('invitations.show', $this->plainToken))
            ->line(__('Zaproszenie jest ważne do :date.', ['date' => $this->invitation->expires_at->translatedFormat('j F Y')]))
            ->line(__('Jeśli nie spodziewasz się tej wiadomości, po prostu ją zignoruj.'));
    }
}
