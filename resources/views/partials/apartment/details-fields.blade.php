<div class="space-y-6">
    <flux:field>
        <flux:label>{{ __('Metraż') }}</flux:label>
        <flux:description>{{ __('Powierzchnia lokalu w metrach kwadratowych. Możesz wpisać np. 48 albo 48,5.') }}</flux:description>
        <flux:input.group class="max-w-48">
            <flux:input wire:model="form.area" inputmode="decimal" placeholder="48,5" />
            <flux:input.group.suffix>m²</flux:input.group.suffix>
        </flux:input.group>
        <flux:error name="form.area" />
    </flux:field>

    <flux:input
        wire:model="form.label"
        :label="__('Nazwa mieszkania')"
        :description="__('Tak mieszkanie będzie podpisane na Twojej liście. Wpisz coś, co łatwo rozpoznasz, np. „Kawalerka na Mokotowie”.')"
        maxlength="100"
    />
</div>
