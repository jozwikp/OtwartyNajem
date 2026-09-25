<?php

use Illuminate\Support\Facades\Auth;
use Illuminate\Validation\Rule;
use Livewire\Attributes\Title;
use Livewire\Component;

new #[Title('Ustawienia wyglądu')] class extends Component {
    public string $locale = '';

    public function mount(): void
    {
        $this->locale = Auth::user()->locale;
    }

    public function updatedLocale(): void
    {
        $this->validate(['locale' => ['required', Rule::in(\App\Support\Locales::codes())]]);

        Auth::user()->update(['locale' => $this->locale]);

        // Full reload, so the menu and every page switch language at once.
        $this->redirectRoute('appearance.edit');
    }
}; ?>

<section class="w-full">
    @include('partials.settings-heading')

    <flux:heading level="2" class="sr-only">{{ __('Appearance settings') }}</flux:heading>

    <x-pages::settings.layout :heading="__('Appearance')" :subheading="__('Update the appearance settings for your account')">
        <flux:radio.group x-data variant="segmented" x-model="$flux.appearance">
            <flux:radio value="light" icon="sun">{{ __('Light') }}</flux:radio>
            <flux:radio value="dark" icon="moon">{{ __('Dark') }}</flux:radio>
            <flux:radio value="system" icon="computer-desktop">{{ __('System') }}</flux:radio>
        </flux:radio.group>

        <div class="mt-10">
            <flux:heading>Język / Language</flux:heading>
            <flux:subheading class="mb-4">{{ __('Język interfejsu, który widzisz. E-maile do najemców zostają po polsku.') }}</flux:subheading>

            <flux:radio.group wire:model.live="locale" variant="segmented">
                @foreach (\App\Support\Locales::SUPPORTED as $code => $name)
                    <flux:radio :value="$code">{{ $name }}</flux:radio>
                @endforeach
            </flux:radio.group>
        </div>
    </x-pages::settings.layout>
</section>
