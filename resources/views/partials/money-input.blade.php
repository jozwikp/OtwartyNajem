{{-- Amount field with a currency suffix. Expects $model, $label, $currency; optional $description, $placeholder, $autofocus. --}}
<flux:field>
    <flux:label>{{ $label }}</flux:label>
    @if (! empty($description))
        <flux:description>{{ $description }}</flux:description>
    @endif
    <flux:input.group class="max-w-56">
        <flux:input wire:model="{{ $model }}" inputmode="decimal" :placeholder="$placeholder ?? '0'" :autofocus="$autofocus ?? false" />
        <flux:input.group.suffix>{{ \App\Support\Currencies::symbol($currency) }}</flux:input.group.suffix>
    </flux:input.group>
    <flux:error name="{{ $model }}" />
</flux:field>
