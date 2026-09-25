{{-- Incoming transfers to confirm. Expects $transactions; uses $selected, $leaseFor and $this->leaseOptions of the payments page. --}}
<ul class="divide-y divide-line rounded-2xl border border-line bg-card">
    @foreach ($transactions as $transaction)
        @php $isSelected = $selected[$transaction->id] ?? false; @endphp
        <li @class(['flex flex-wrap items-start gap-3 p-4 transition', 'bg-badge/40' => $isSelected]) wire:key="tx-{{ $transaction->id }}">
            <flux:checkbox wire:model.live="selected.{{ $transaction->id }}" :aria-label="__('Zaksięguj')" class="mt-1" />

            <div class="min-w-0 flex-1 space-y-1">
                <div class="flex flex-wrap items-baseline gap-x-3">
                    <span class="text-lg font-semibold">{{ \App\Support\Money::format($transaction->amount, $transaction->currency) }}</span>
                    <span class="text-sm text-stone-500">{{ $transaction->booked_on->format('d.m.Y') }}</span>
                    @if ($transaction->confidence === 'high')
                        <flux:badge size="sm" color="green">{{ __('pewne') }}</flux:badge>
                    @elseif ($transaction->confidence === 'medium')
                        <flux:badge size="sm" color="amber">{{ __('prawdopodobne') }}</flux:badge>
                    @endif
                </div>
                <div class="text-sm">
                    <span class="font-medium">{{ $transaction->sender_name ?? __('Nieznany nadawca') }}</span>
                    @if ($transaction->title)
                        <span class="text-stone-600 dark:text-stone-300">· „{{ $transaction->title }}”</span>
                    @endif
                </div>
                @if ($transaction->match_reason)
                    <flux:text class="text-xs">{{ $transaction->match_reason }}</flux:text>
                @endif
            </div>

            <div class="flex w-full flex-col gap-2 sm:w-80">
                <flux:select wire:model.live="leaseFor.{{ $transaction->id }}" size="sm" :aria-label="__('Najem')">
                    <flux:select.option value="">{{ __('— wybierz najemcę —') }}</flux:select.option>
                    @foreach ($this->leaseOptions as $id => $option)
                        <flux:select.option :value="$id">{{ $option['label'] }}</flux:select.option>
                    @endforeach
                </flux:select>
                <flux:button size="xs" variant="ghost" icon="x-mark" wire:click="skip({{ $transaction->id }})" class="self-end">{{ __('To nie od najemcy') }}</flux:button>
            </div>
        </li>
    @endforeach
</ul>
