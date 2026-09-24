{{-- Deposit. Expects $form (LeaseForm). --}}
<div class="space-y-6">
    <flux:switch wire:model.live="form.has_deposit" :label="__('Najemca wpłacił kaucję')" align="left" />

    @if ($form->has_deposit)
        @include('partials.money-input', ['model' => 'form.deposit_amount', 'label' => __('Kwota kaucji'), 'currency' => $form->currency])

        <flux:radio.group wire:model="form.deposit_method" :label="__('Jak kaucja została wpłacona?')" variant="cards" class="max-sm:flex-col">
            @foreach (\App\Enums\PaymentMethod::cases() as $method)
                <flux:radio :value="$method->value" :label="$method->label()" />
            @endforeach
        </flux:radio.group>

        <flux:text class="text-sm">{{ __('Kaucja nie jest wliczana do rozliczeń. Na koniec najmu zapiszesz, ile zostało zwrócone.') }}</flux:text>
    @endif
</div>
