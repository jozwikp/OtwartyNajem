<?php

use App\Actions\Apartments\AcceptInvitation;
use App\Actions\Apartments\DeclineInvitation;
use App\Models\ApartmentInvitation;
use Flux\Flux;
use Illuminate\Support\Facades\Auth;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Locked;
use Livewire\Attributes\Title;
use Livewire\Component;

new #[Layout('layouts::auth')] #[Title('Zaproszenie')] class extends Component {
    #[Locked]
    public string $token;

    public ?ApartmentInvitation $invitation = null;

    public function mount(string $token): void
    {
        $this->token = $token;
        $this->invitation = ApartmentInvitation::findByPlainToken($token)?->load(['apartment', 'inviter']);

        if (Auth::guest() && $this->invitation?->isPending()) {
            // After signing in or creating an account, come back here.
            session()->put('url.intended', route('invitations.show', $token));
            session()->put('invitation_email', $this->invitation->email);
        }
    }

    public function accept(AcceptInvitation $acceptInvitation): void
    {
        $invitation = $this->pendingInvitation();

        $acceptInvitation->handle($invitation, Auth::user());
        session()->forget('invitation_email');

        Flux::toast(variant: 'success', text: __('Witaj! Jesteś teraz współwłaścicielem mieszkania „:label”.', ['label' => $invitation->apartment->label]));

        $this->redirectRoute('apartments.show', $invitation->apartment, navigate: true);
    }

    public function decline(DeclineInvitation $declineInvitation): void
    {
        $declineInvitation->handle($this->pendingInvitation(), Auth::user());
        session()->forget('invitation_email');

        $this->invitation->refresh();
    }

    protected function pendingInvitation(): ApartmentInvitation
    {
        abort_unless(Auth::check(), 403);

        $invitation = ApartmentInvitation::findByPlainToken($this->token);
        abort_unless($invitation?->isPending() && $invitation->apartment, 404);

        return $invitation;
    }
}; ?>

<div class="flex flex-col gap-6">
    @if (! $invitation || ! $invitation->apartment)
        <x-auth-header :title="__('Nie znaleziono zaproszenia')" :description="__('Link jest nieprawidłowy albo zaproszenie zostało anulowane. Poproś osobę, która Cię zaprosiła, o wysłanie nowego.')" />
    @elseif ($invitation->isAccepted())
        <x-auth-header :title="__('Zaproszenie zostało już przyjęte')" :description="__('Mieszkanie znajdziesz na liście swoich mieszkań.')" />
        @auth
            <flux:button variant="primary" :href="route('apartments.index')" wire:navigate>{{ __('Przejdź do moich mieszkań') }}</flux:button>
        @else
            <flux:button variant="primary" :href="route('login')" wire:navigate>{{ __('Zaloguj się') }}</flux:button>
        @endauth
    @elseif ($invitation->isDeclined())
        <x-auth-header :title="__('Zaproszenie zostało odrzucone')" :description="__('Jeśli to pomyłka, poproś o wysłanie zaproszenia ponownie.')" />
    @elseif ($invitation->isExpired())
        <x-auth-header :title="__('Zaproszenie wygasło')" :description="__('Poproś osobę, która Cię zaprosiła, o wysłanie go ponownie.')" />
    @else
        <x-auth-header
            :title="__('Zaproszenie do mieszkania')"
            :description="__(':name zaprasza Cię do wspólnego zarządzania mieszkaniem.', ['name' => $invitation->inviter?->name ?? __('Właściciel')])"
        />

        <div class="rounded-xl border border-zinc-200 p-4 text-center dark:border-zinc-700">
            <div class="font-semibold">{{ $invitation->apartment->label }}</div>
            <flux:text class="text-sm">{{ $invitation->apartment->full_address }}</flux:text>
        </div>

        @auth
            <flux:text class="text-center text-sm">
                {{ __('Przyjmiesz zaproszenie jako :name (:email).', ['name' => auth()->user()->name, 'email' => auth()->user()->email]) }}
            </flux:text>

            <div class="flex flex-col gap-2">
                <flux:button variant="primary" icon="check" wire:click="accept" class="w-full">{{ __('Przyjmuję zaproszenie') }}</flux:button>
                <flux:button variant="ghost" wire:click="decline" wire:confirm="{{ __('Na pewno odrzucić zaproszenie?') }}" class="w-full">{{ __('Odrzuć') }}</flux:button>
            </div>
        @else
            <flux:text class="text-center">{{ __('Aby przyjąć zaproszenie, zaloguj się albo załóż darmowe konto.') }}</flux:text>

            <div class="flex flex-col gap-2">
                <flux:button variant="primary" :href="route('register')" class="w-full">{{ __('Załóż konto') }}</flux:button>
                <flux:button :href="route('login')" class="w-full">{{ __('Mam już konto – zaloguj się') }}</flux:button>
            </div>
        @endauth
    @endif
</div>
