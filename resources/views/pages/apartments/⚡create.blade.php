<?php

use App\Actions\Apartments\CreateApartment;
use App\Livewire\Forms\ApartmentForm;
use Flux\Flux;
use Illuminate\Support\Facades\Auth;
use Livewire\Attributes\Title;
use Livewire\Component;

new #[Title('Dodaj mieszkanie')] class extends Component {
    public ApartmentForm $form;

    public int $step = 1;

    /**
     * Last label we suggested, so we only overwrite the label when the user hasn't changed it.
     */
    public string $suggestedLabel = '';

    public function next(): void
    {
        $this->form->normalize();

        if ($this->step === 1) {
            $this->form->validate($this->form->addressRules());

            if ($this->form->label === '' || $this->form->label === $this->suggestedLabel) {
                $this->form->label = $this->suggestedLabel = $this->form->suggestedLabel();
            }
        }

        if ($this->step === 2) {
            $this->form->validate($this->form->detailsRules());
        }

        $this->step = min($this->step + 1, 3);
    }

    public function back(): void
    {
        $this->resetValidation();
        $this->step = max($this->step - 1, 1);
    }

    public function goTo(int $step): void
    {
        if ($step < $this->step) {
            $this->step = $step;
        }
    }

    public function save(CreateApartment $createApartment): void
    {
        $this->form->normalize();
        $this->form->validate();

        $apartment = $createApartment->handle(Auth::user(), $this->form->data());

        Flux::toast(variant: 'success', text: __('Mieszkanie zostało dodane.'));

        $this->redirectRoute('apartments.show', $apartment, navigate: true);
    }
}; ?>

<div class="mx-auto w-full max-w-2xl">
    <flux:breadcrumbs class="mb-4">
        <flux:breadcrumbs.item :href="route('apartments.index')" wire:navigate>{{ __('Moje mieszkania') }}</flux:breadcrumbs.item>
        <flux:breadcrumbs.item>{{ __('Nowe mieszkanie') }}</flux:breadcrumbs.item>
    </flux:breadcrumbs>

    <flux:heading size="xl" level="1" class="mb-6">{{ __('Dodaj mieszkanie') }}</flux:heading>

    @include('partials.steps', ['steps' => [__('Adres'), __('Szczegóły'), __('Podsumowanie')], 'current' => $step])

    <flux:card class="p-6 sm:p-8">
        @if ($step === 1)
            <form wire:submit="next" wire:key="step-1">
                <flux:heading size="lg">{{ __('Gdzie jest mieszkanie?') }}</flux:heading>
                <flux:text class="mb-6 mt-1">{{ __('Wpisz adres tak, jak na kopercie.') }}</flux:text>

                @include('partials.apartment.address-fields')

                <div class="mt-8 flex justify-between gap-3">
                    <flux:button :href="route('apartments.index')" wire:navigate variant="ghost">{{ __('Anuluj') }}</flux:button>
                    <flux:button type="submit" variant="primary" icon:trailing="arrow-right">{{ __('Dalej') }}</flux:button>
                </div>
            </form>
        @elseif ($step === 2)
            <form wire:submit="next" wire:key="step-2">
                <flux:heading size="lg">{{ __('Kilka szczegółów') }}</flux:heading>
                <flux:text class="mb-6 mt-1">{{ __('Jeszcze tylko metraż i nazwa, po której rozpoznasz mieszkanie.') }}</flux:text>

                @include('partials.apartment.details-fields')

                <div class="mt-8 flex justify-between gap-3">
                    <flux:button wire:click="back" variant="ghost" icon="arrow-left">{{ __('Wstecz') }}</flux:button>
                    <flux:button type="submit" variant="primary" icon:trailing="arrow-right">{{ __('Dalej') }}</flux:button>
                </div>
            </form>
        @else
            <div wire:key="step-3">
                <flux:heading size="lg">{{ __('Sprawdź, czy wszystko się zgadza') }}</flux:heading>
                <flux:text class="mb-6 mt-1">{{ __('Jeśli coś trzeba poprawić, kliknij „Zmień”.') }}</flux:text>

                <div class="divide-y divide-zinc-200 rounded-xl border border-zinc-200 dark:divide-zinc-700 dark:border-zinc-700">
                    <div class="flex items-start justify-between gap-4 p-4">
                        <div>
                            <flux:text class="text-sm">{{ __('Adres') }}</flux:text>
                            <div class="mt-1 font-medium">
                                {{ $form->street }}@if ($form->unit_number !== '') / {{ __('lok.') }} {{ $form->unit_number }}@endif<br>
                                {{ $form->postal_code }} {{ $form->city }}<br>
                                {{ \App\Support\Countries::name($form->country_code) }}
                            </div>
                        </div>
                        <flux:button size="sm" variant="ghost" wire:click="goTo(1)">{{ __('Zmień') }}</flux:button>
                    </div>
                    <div class="flex items-start justify-between gap-4 p-4">
                        <div>
                            <flux:text class="text-sm">{{ __('Metraż') }}</flux:text>
                            <div class="mt-1 font-medium">{{ \Illuminate\Support\Number::format((float) $form->area, maxPrecision: 2, locale: 'pl') }} m²</div>
                        </div>
                        <flux:button size="sm" variant="ghost" wire:click="goTo(2)">{{ __('Zmień') }}</flux:button>
                    </div>
                    <div class="flex items-start justify-between gap-4 p-4">
                        <div>
                            <flux:text class="text-sm">{{ __('Nazwa na liście') }}</flux:text>
                            <div class="mt-1 font-medium">{{ $form->label }}</div>
                        </div>
                        <flux:button size="sm" variant="ghost" wire:click="goTo(2)">{{ __('Zmień') }}</flux:button>
                    </div>
                </div>

                <div class="mt-8 flex justify-between gap-3">
                    <flux:button wire:click="back" variant="ghost" icon="arrow-left">{{ __('Wstecz') }}</flux:button>
                    <flux:button wire:click="save" variant="primary" icon="check">{{ __('Zapisz mieszkanie') }}</flux:button>
                </div>
            </div>
        @endif
    </flux:card>
</div>
