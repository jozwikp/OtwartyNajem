{{-- Contract terms. Expects $form (LeaseForm); optional $lockStart (start date and currency can't change). --}}
<div class="space-y-6">
    <div class="grid gap-6 sm:grid-cols-2">
        <flux:input type="date" wire:model="form.starts_on" :label="__('Początek umowy')" :disabled="$lockStart ?? false" />

        <div class="space-y-3">
            <flux:input type="date" wire:model="form.ends_on" :label="__('Koniec umowy')" :disabled="$form->open_ended" />
            <flux:checkbox wire:model.live="form.open_ended" :label="__('Umowa na czas nieokreślony')" />
        </div>
    </div>

    @if ($lockStart ?? false)
        <flux:text class="-mt-3 text-sm">{{ __('Początku umowy i waluty nie można już zmienić, bo są naliczone opłaty.') }}</flux:text>
    @endif

    <flux:input
        type="number" min="1" max="31"
        wire:model="form.payment_due_day"
        :label="__('Termin płatności – do którego dnia miesiąca')"
        :description="__('Np. 10 oznacza, że opłaty za dany miesiąc trzeba zapłacić do 10. dnia tego miesiąca.')"
        class="max-w-28"
    />

    <flux:select wire:model.live="form.currency" :label="__('Waluta')" :description="__('Wszystkie opłaty, rachunki i wpłaty w tym najmie będą w tej walucie.')" class="max-w-80" :disabled="$lockStart ?? false">
        @foreach (\App\Support\Currencies::all() as $code => $name)
            <flux:select.option :value="$code">{{ $name }}</flux:select.option>
        @endforeach
    </flux:select>

    <flux:input
        wire:model="form.bank_account"
        :label="__('Numer konta do wpłat')"
        :badge="__('Opcjonalnie')"
        placeholder="00 0000 0000 0000 0000 0000 0000"
        inputmode="text"
    />

    <flux:textarea
        wire:model="form.notes"
        :label="__('Informacje dodatkowe')"
        :badge="__('Opcjonalnie')"
        :placeholder="__('Np. okres wypowiedzenia, liczba kluczy, stan liczników przy wprowadzeniu…')"
        rows="3"
    />
</div>
