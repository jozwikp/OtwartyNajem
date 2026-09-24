<?php

use App\Actions\Leases\UpdateLease;
use App\Livewire\Forms\LeaseForm;
use App\Models\Apartment;
use App\Models\Lease;
use Flux\Flux;
use Illuminate\Validation\ValidationException;
use Livewire\Attributes\Title;
use Livewire\Component;

new #[Title('Edytuj umowę')] class extends Component {
    public Apartment $apartment;

    public Lease $lease;

    public LeaseForm $form;

    public bool $lockStart = false;

    public function mount(Apartment $apartment, Lease $lease): void
    {
        $this->authorize('update', $apartment);

        $this->apartment = $apartment;
        $this->lease = $lease;
        $this->form->fillFrom($lease);
        $this->lockStart = $lease->hasLedgerEntries();
    }

    public function save(UpdateLease $updateLease): void
    {
        $this->authorize('update', $this->apartment);

        $this->form->validate();

        $data = $this->form->data();
        if ($this->lockStart) {
            unset($data['starts_on'], $data['currency']);
        }

        try {
            $updateLease->handle($this->lease, $data);
        } catch (ValidationException $e) {
            foreach ($e->errors() as $field => $messages) {
                $this->addError('form.'.$field, $messages[0]);
            }

            return;
        }

        Flux::toast(variant: 'success', text: __('Zmiany w umowie zapisane.'));

        $this->redirectRoute('leases.show', [$this->apartment, $this->lease], navigate: true);
    }
}; ?>

<div>
    @include('partials.apartment.header', ['current' => 'leases.show'])

    <form wire:submit="save" class="mx-auto w-full max-w-2xl space-y-6">
        <flux:heading size="lg">{{ __('Edytuj umowę – :names', ['names' => $lease->tenantNames()]) }}</flux:heading>

        <flux:card class="bg-tray p-6 sm:p-8">
            <flux:heading size="lg" class="mb-6">{{ __('Warunki umowy') }}</flux:heading>
            @include('partials.lease.terms-fields', ['lockStart' => $lockStart])
        </flux:card>

        <flux:card class="bg-tray p-6 sm:p-8">
            <flux:heading size="lg" class="mb-6">{{ __('Kaucja') }}</flux:heading>
            @include('partials.lease.deposit-fields')
        </flux:card>

        <flux:text class="text-sm">{{ __('Najemców i stałe opłaty zmienisz na karcie najmu.') }}</flux:text>

        <div class="flex justify-end gap-3">
            <flux:button :href="route('leases.show', [$apartment, $lease])" wire:navigate variant="ghost">{{ __('Anuluj') }}</flux:button>
            <flux:button type="submit" variant="primary" icon="check">{{ __('Zapisz zmiany') }}</flux:button>
        </div>
    </form>
</div>
