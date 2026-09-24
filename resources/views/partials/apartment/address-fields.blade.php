<div class="space-y-6">
    <flux:input
        wire:model="form.street"
        :label="__('Ulica i numer budynku')"
        :placeholder="__('np. Puławska 12A')"
        autocomplete="address-line1"
        autofocus
    />

    <flux:input
        wire:model="form.unit_number"
        :label="__('Numer lokalu')"
        :badge="__('Opcjonalnie')"
        :description="__('Zostaw puste, jeśli wynajmujesz cały budynek.')"
        :placeholder="__('np. 5')"
        class="max-w-40"
    />

    <div class="grid gap-6 sm:grid-cols-[10rem_1fr]">
        <flux:input
            wire:model="form.postal_code"
            :label="__('Kod pocztowy')"
            :placeholder="$form->country_code === 'PL' ? '00-000' : ''"
            autocomplete="postal-code"
        />

        <flux:input
            wire:model="form.city"
            :label="__('Miasto')"
            :placeholder="__('np. Warszawa')"
            autocomplete="address-level2"
        />
    </div>

    <flux:select wire:model.live="form.country_code" :label="__('Kraj')" class="max-w-80">
        @foreach (\App\Support\Countries::all() as $code => $name)
            <flux:select.option :value="$code">{{ $name }}</flux:select.option>
            @if ($code === last(\App\Support\Countries::PINNED))
                <flux:select.option disabled>──────────</flux:select.option>
            @endif
        @endforeach
    </flux:select>
</div>
