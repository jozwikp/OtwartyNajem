<?php

use App\Livewire\Forms\ApartmentForm;
use App\Models\Apartment;
use Flux\Flux;
use Livewire\Attributes\Title;
use Livewire\Component;

new #[Title('Edytuj mieszkanie')] class extends Component {
    public Apartment $apartment;

    public ApartmentForm $form;

    public function mount(Apartment $apartment): void
    {
        $this->authorize('update', $apartment);

        $this->apartment = $apartment;
        $this->form->fillFrom($apartment);
    }

    public function save(): void
    {
        $this->authorize('update', $this->apartment);

        $this->form->normalize();
        $this->form->validate();

        $this->apartment->update($this->form->data());

        Flux::toast(variant: 'success', text: __('Zmiany zostały zapisane.'));

        $this->redirectRoute('apartments.show', $this->apartment, navigate: true);
    }

    public function delete(): void
    {
        $this->authorize('delete', $this->apartment);

        $this->apartment->delete();

        Flux::toast(variant: 'success', text: __('Mieszkanie „:label” zostało usunięte.', ['label' => $this->apartment->label]));

        $this->redirectRoute('apartments.index', navigate: true);
    }
}; ?>

<div>
    @include('partials.apartment.header', ['current' => 'apartments.show'])

    <div class="mx-auto w-full max-w-5xl">

    <form wire:submit="save" class="max-w-2xl space-y-6">
        <flux:card class="bg-tray p-6 sm:p-8">
            <flux:heading size="lg" class="mb-6">{{ __('Adres') }}</flux:heading>
            @include('partials.apartment.address-fields')
        </flux:card>

        <flux:card class="bg-tray p-6 sm:p-8">
            <flux:heading size="lg" class="mb-6">{{ __('Szczegóły') }}</flux:heading>
            @include('partials.apartment.details-fields')
        </flux:card>

        <div class="flex justify-end gap-3">
            <flux:button :href="route('apartments.show', $apartment)" wire:navigate variant="ghost">{{ __('Anuluj') }}</flux:button>
            <flux:button type="submit" variant="primary" icon="check">{{ __('Zapisz zmiany') }}</flux:button>
        </div>
    </form>

    <div class="mt-12 max-w-2xl rounded-2xl border border-red-200 bg-card p-6 dark:border-red-900/60">
        <flux:heading>{{ __('Usuń mieszkanie') }}</flux:heading>
        <flux:text class="mt-1">{{ __('Mieszkanie zniknie z listy u Ciebie i u pozostałych właścicieli.') }}</flux:text>

        <flux:modal.trigger name="delete-apartment">
            <flux:button variant="danger" icon="trash" class="mt-4">{{ __('Usuń mieszkanie') }}</flux:button>
        </flux:modal.trigger>
    </div>

    <flux:modal name="delete-apartment" class="max-w-md">
        <div class="space-y-6">
            <div>
                <flux:heading size="lg">{{ __('Na pewno usunąć to mieszkanie?') }}</flux:heading>
                <flux:text class="mt-2">
                    {{ __('Mieszkanie „:label” zniknie z listy u wszystkich właścicieli. Informacja o usunięciu zostanie zapisana w historii zmian.', ['label' => $apartment->label]) }}
                </flux:text>
            </div>

            <div class="flex justify-end gap-2">
                <flux:modal.close>
                    <flux:button variant="ghost">{{ __('Nie, zostaw') }}</flux:button>
                </flux:modal.close>
                <flux:button variant="danger" wire:click="delete">{{ __('Tak, usuń') }}</flux:button>
            </div>
        </div>
    </flux:modal>
    </div>
</div>
