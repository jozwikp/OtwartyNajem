<?php

use App\Models\Apartment;
use Livewire\Attributes\Title;
use Livewire\Component;

new #[Title('Mieszkanie')] class extends Component {
    public Apartment $apartment;

    public function mount(Apartment $apartment): void
    {
        $this->apartment = $apartment->load(['owners', 'creator']);
    }
}; ?>

<div class="mx-auto w-full max-w-5xl">
    @include('partials.apartment.header', ['current' => 'apartments.show'])

    <div class="grid gap-6 lg:grid-cols-3">
        <flux:card class="lg:col-span-2">
            <div class="mb-4 flex items-center justify-between gap-4">
                <flux:heading size="lg">{{ __('Dane mieszkania') }}</flux:heading>
                <flux:button size="sm" icon="pencil-square" :href="route('apartments.edit', $apartment)" wire:navigate>
                    {{ __('Edytuj') }}
                </flux:button>
            </div>

            <dl class="divide-y divide-zinc-100 dark:divide-zinc-800">
                @foreach ([
                    __('Ulica i numer budynku') => $apartment->street,
                    __('Numer lokalu') => $apartment->unit_number ?? __('— (cały budynek)'),
                    __('Kod pocztowy') => $apartment->postal_code,
                    __('Miasto') => $apartment->city,
                    __('Kraj') => $apartment->country_name,
                    __('Metraż') => $apartment->area_formatted,
                    __('Nazwa na liście') => $apartment->label,
                ] as $term => $value)
                    <div class="grid gap-1 py-3 sm:grid-cols-3 sm:gap-4">
                        <dt class="text-sm text-zinc-500 dark:text-zinc-400">{{ $term }}</dt>
                        <dd class="font-medium sm:col-span-2">{{ $value }}</dd>
                    </div>
                @endforeach
            </dl>
        </flux:card>

        <div class="space-y-6">
            <flux:card>
                <div class="mb-4 flex items-center justify-between gap-4">
                    <flux:heading size="lg">{{ __('Właściciele') }}</flux:heading>
                    <flux:link :href="route('apartments.owners', $apartment)" wire:navigate class="text-sm">{{ __('Zarządzaj') }}</flux:link>
                </div>

                <ul class="space-y-3">
                    @foreach ($apartment->owners as $owner)
                        <li class="flex items-center gap-3" wire:key="owner-{{ $owner->id }}">
                            <flux:avatar size="sm" :name="$owner->name" :initials="$owner->initials()" />
                            <div class="min-w-0">
                                <div class="truncate text-sm font-medium">
                                    {{ $owner->name }}
                                    @if ($owner->is(auth()->user()))
                                        <span class="text-zinc-500">({{ __('Ty') }})</span>
                                    @endif
                                </div>
                                <div class="truncate text-xs text-zinc-500">{{ $owner->email }}</div>
                            </div>
                        </li>
                    @endforeach
                </ul>

                <flux:button size="sm" icon="user-plus" class="mt-4 w-full" :href="route('apartments.owners', $apartment)" wire:navigate>
                    {{ __('Zaproś współwłaściciela') }}
                </flux:button>
            </flux:card>

            <flux:text class="px-1 text-sm">
                {{ __('Dodano :date', ['date' => $apartment->created_at->translatedFormat('j F Y')]) }}@if ($apartment->creator), {{ __('przez') }} {{ $apartment->creator->name }}@endif.
                <flux:link :href="route('apartments.history', $apartment)" wire:navigate>{{ __('Zobacz historię zmian') }}</flux:link>
            </flux:text>
        </div>
    </div>
</div>
