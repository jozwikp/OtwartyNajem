<?php

use App\Actions\Apartments\CancelInvitation;
use App\Actions\Apartments\InviteOwner;
use App\Actions\Apartments\RemoveOwner;
use App\Actions\Apartments\ResendInvitation;
use App\Models\Apartment;
use App\Models\ApartmentInvitation;
use App\Models\User;
use Flux\Flux;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\Auth;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Title;
use Livewire\Component;

new #[Title('Właściciele')] class extends Component {
    public Apartment $apartment;

    public string $email = '';

    public ?int $ownerToRemove = null;

    public function mount(Apartment $apartment): void
    {
        $this->apartment = $apartment;
    }

    /**
     * @return Collection<int, User>
     */
    #[Computed]
    public function owners(): Collection
    {
        return $this->apartment->owners()->orderBy('apartment_owners.created_at')->get();
    }

    /**
     * Invitations that were not accepted (waiting, declined or expired).
     *
     * @return Collection<int, ApartmentInvitation>
     */
    #[Computed]
    public function invitations(): Collection
    {
        return $this->apartment->invitations()->whereNull('accepted_at')->with('inviter')->latest()->get();
    }

    #[Computed]
    public function selectedOwner(): ?User
    {
        return $this->owners->firstWhere('id', $this->ownerToRemove);
    }

    public function invite(InviteOwner $inviteOwner): void
    {
        $this->authorize('manageOwners', $this->apartment);

        $this->email = trim($this->email);
        $this->validate(['email' => ['required', 'email', 'max:255']], attributes: ['email' => __('adres e-mail')]);

        $inviteOwner->handle($this->apartment, Auth::user(), $this->email);

        Flux::modal('invite-owner')->close();
        Flux::toast(variant: 'success', text: __('Zaproszenie wysłane na adres :email.', ['email' => $this->email]));

        $this->reset('email');
        unset($this->invitations);
    }

    public function resend(int $invitationId, ResendInvitation $resendInvitation): void
    {
        $this->authorize('manageOwners', $this->apartment);

        $invitation = $this->apartment->invitations()->whereNull('accepted_at')->findOrFail($invitationId);
        $resendInvitation->handle($invitation);

        Flux::toast(variant: 'success', text: __('Wysłaliśmy zaproszenie ponownie na adres :email.', ['email' => $invitation->email]));
        unset($this->invitations);
    }

    public function cancel(int $invitationId, CancelInvitation $cancelInvitation): void
    {
        $this->authorize('manageOwners', $this->apartment);

        $invitation = $this->apartment->invitations()->whereNull('accepted_at')->findOrFail($invitationId);
        $cancelInvitation->handle($invitation);

        Flux::toast(text: __('Zaproszenie dla :email zostało anulowane.', ['email' => $invitation->email]));
        unset($this->invitations);
    }

    public function confirmRemoval(int $userId): void
    {
        $this->ownerToRemove = $userId;
        Flux::modal('remove-owner')->show();
    }

    public function remove(RemoveOwner $removeOwner): void
    {
        $this->authorize('manageOwners', $this->apartment);

        $owner = $this->selectedOwner;
        abort_unless($owner, 404);

        $removeOwner->handle($this->apartment, $owner, Auth::user());

        Flux::modal('remove-owner')->close();

        if ($owner->is(Auth::user())) {
            Flux::toast(text: __('Nie jesteś już właścicielem mieszkania „:label”.', ['label' => $this->apartment->label]));
            $this->redirectRoute('apartments.index', navigate: true);

            return;
        }

        Flux::toast(text: __(':name nie jest już właścicielem tego mieszkania.', ['name' => $owner->name]));
        $this->reset('ownerToRemove');
        unset($this->owners);
    }
}; ?>

<div class="mx-auto w-full max-w-5xl">
    @include('partials.apartment.header', ['current' => 'apartments.owners'])

    <div class="max-w-3xl space-y-8">
        <flux:callout icon="information-circle" color="zinc">
            <flux:callout.text>
                {{ __('Wszyscy właściciele mają takie same uprawnienia: mogą zmieniać dane mieszkania, zapraszać i usuwać innych właścicieli. Każda zmiana jest zapisywana w historii – widać w niej, kto co zrobił.') }}
            </flux:callout.text>
        </flux:callout>

        <section>
            <div class="mb-4 flex flex-wrap items-center justify-between gap-4">
                <flux:heading size="lg">{{ __('Właściciele') }} ({{ $this->owners->count() }})</flux:heading>

                <flux:modal.trigger name="invite-owner">
                    <flux:button variant="primary" icon="user-plus">{{ __('Zaproś współwłaściciela') }}</flux:button>
                </flux:modal.trigger>
            </div>

            <ul class="divide-y divide-zinc-200 rounded-2xl border border-zinc-200 dark:divide-zinc-700 dark:border-zinc-700">
                @foreach ($this->owners as $owner)
                    @php $isMe = $owner->is(auth()->user()); @endphp
                    <li class="flex flex-wrap items-center gap-4 p-4" wire:key="owner-{{ $owner->id }}">
                        <flux:avatar :name="$owner->name" :initials="$owner->initials()" />
                        <div class="min-w-0 flex-1">
                            <div class="flex items-center gap-2 font-medium">
                                {{ $owner->name }}
                                @if ($isMe)
                                    <flux:badge size="sm" color="zinc">{{ __('To Ty') }}</flux:badge>
                                @endif
                            </div>
                            <flux:text class="truncate text-sm">{{ $owner->email }}</flux:text>
                            <flux:text class="text-xs">{{ __('Właściciel od :date', ['date' => \Illuminate\Support\Carbon::parse($owner->pivot->created_at)->translatedFormat('j F Y')]) }}</flux:text>
                        </div>

                        @if ($this->owners->count() > 1)
                            <flux:button size="sm" variant="ghost" :icon="$isMe ? 'arrow-left-start-on-rectangle' : 'user-minus'" wire:click="confirmRemoval({{ $owner->id }})">
                                {{ $isMe ? __('Zrezygnuj') : __('Usuń') }}
                            </flux:button>
                        @endif
                    </li>
                @endforeach
            </ul>

            @if ($this->owners->count() === 1)
                <flux:text class="mt-2 text-sm">{{ __('Jesteś jedynym właścicielem. Mieszkanie zawsze musi mieć co najmniej jednego właściciela.') }}</flux:text>
            @endif
        </section>

        @if ($this->invitations->isNotEmpty())
            <section>
                <flux:heading size="lg" class="mb-4">{{ __('Wysłane zaproszenia') }}</flux:heading>

                <ul class="divide-y divide-zinc-200 rounded-2xl border border-zinc-200 dark:divide-zinc-700 dark:border-zinc-700">
                    @foreach ($this->invitations as $invitation)
                        <li class="flex flex-wrap items-center gap-4 p-4" wire:key="invitation-{{ $invitation->id }}">
                            <div class="flex size-10 items-center justify-center rounded-full bg-zinc-100 dark:bg-zinc-800">
                                <flux:icon.envelope variant="mini" class="text-zinc-500" />
                            </div>
                            <div class="min-w-0 flex-1">
                                <div class="flex flex-wrap items-center gap-2 font-medium">
                                    {{ $invitation->email }}
                                    @if ($invitation->isDeclined())
                                        <flux:badge size="sm" color="red">{{ __('Odrzucone') }}</flux:badge>
                                    @elseif ($invitation->isExpired())
                                        <flux:badge size="sm" color="amber">{{ __('Wygasło') }}</flux:badge>
                                    @else
                                        <flux:badge size="sm" color="sky">{{ __('Czeka na odpowiedź') }}</flux:badge>
                                    @endif
                                </div>
                                <flux:text class="text-xs">
                                    {{ __('Wysłane :date', ['date' => $invitation->updated_at->translatedFormat('j F Y')]) }}@if ($invitation->inviter), {{ __('przez') }} {{ $invitation->inviter->name }}@endif
                                    @if ($invitation->isPending())
                                        · {{ __('ważne do :date', ['date' => $invitation->expires_at->translatedFormat('j F Y')]) }}
                                    @endif
                                </flux:text>
                            </div>

                            <div class="flex gap-2">
                                <flux:button size="sm" icon="arrow-path" wire:click="resend({{ $invitation->id }})">{{ __('Wyślij ponownie') }}</flux:button>
                                <flux:button size="sm" variant="ghost" wire:click="cancel({{ $invitation->id }})" wire:confirm="{{ __('Anulować zaproszenie dla :email? Link z maila przestanie działać.', ['email' => $invitation->email]) }}">
                                    {{ __('Anuluj') }}
                                </flux:button>
                            </div>
                        </li>
                    @endforeach
                </ul>
            </section>
        @endif
    </div>

    <flux:modal name="invite-owner" class="w-full max-w-md" @close="$wire.resetValidation()">
        <form wire:submit="invite" class="space-y-6">
            <div>
                <flux:heading size="lg">{{ __('Zaproś współwłaściciela') }}</flux:heading>
                <flux:text class="mt-2">
                    {{ __('Wyślemy tej osobie maila z linkiem. Po kliknięciu i zalogowaniu (albo założeniu konta) będzie mogła zarządzać tym mieszkaniem razem z Tobą.') }}
                </flux:text>
            </div>

            <flux:input wire:model="email" type="email" :label="__('Adres e-mail')" placeholder="jan.kowalski@example.com" autofocus />

            <div class="flex justify-end gap-2">
                <flux:modal.close>
                    <flux:button variant="ghost">{{ __('Anuluj') }}</flux:button>
                </flux:modal.close>
                <flux:button type="submit" variant="primary" icon="paper-airplane">{{ __('Wyślij zaproszenie') }}</flux:button>
            </div>
        </form>
    </flux:modal>

    <flux:modal name="remove-owner" class="w-full max-w-md">
        @if ($this->selectedOwner)
            @php $removingMe = $this->selectedOwner->is(auth()->user()); @endphp
            <div class="space-y-6">
                <div>
                    <flux:heading size="lg">
                        {{ $removingMe ? __('Zrezygnować z tego mieszkania?') : __('Usunąć :name?', ['name' => $this->selectedOwner->name]) }}
                    </flux:heading>
                    <flux:text class="mt-2">
                        @if ($removingMe)
                            {{ __('Stracisz dostęp do mieszkania „:label”. Pozostali właściciele nadal będą mogli nim zarządzać i w razie potrzeby zaprosić Cię ponownie.', ['label' => $apartment->label]) }}
                        @else
                            {{ __(':name straci dostęp do mieszkania „:label”. Możesz zaprosić tę osobę ponownie w dowolnym momencie.', ['name' => $this->selectedOwner->name, 'label' => $apartment->label]) }}
                        @endif
                    </flux:text>
                </div>

                <flux:error name="owner" />

                <div class="flex justify-end gap-2">
                    <flux:modal.close>
                        <flux:button variant="ghost">{{ __('Nie, zostaw') }}</flux:button>
                    </flux:modal.close>
                    <flux:button variant="danger" wire:click="remove">{{ $removingMe ? __('Tak, rezygnuję') : __('Tak, usuń') }}</flux:button>
                </div>
            </div>
        @endif
    </flux:modal>
</div>
