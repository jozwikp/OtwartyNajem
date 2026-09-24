{{-- One entry of the change history. Expects $activity; optional $showSubject to show which apartment it concerns. --}}
@php $presenter = new \App\Support\ActivityPresenter($activity); @endphp

<li class="relative flex gap-4 pb-6 last:pb-0" wire:key="activity-{{ $activity->id }}">
    <div class="flex size-9 shrink-0 items-center justify-center rounded-full bg-badge">
        <flux:icon :name="$presenter->icon()" variant="mini" class="text-accent-content" />
    </div>

    <div class="min-w-0 flex-1 pt-1">
        <div class="font-medium">{{ $presenter->title() }}</div>

        @if (($showSubject ?? false) && ($apartment = $presenter->apartment()))
            <flux:link :href="route('apartments.show', $apartment)" wire:navigate class="text-sm">{{ $apartment->label }}</flux:link>
        @endif

        <flux:text class="text-sm">
            {{ $presenter->causerName() }} ·
            <time datetime="{{ $activity->created_at->toIso8601String() }}" title="{{ $activity->created_at->translatedFormat('j F Y, H:i') }}">
                {{ $activity->created_at->diffForHumans() }}
            </time>
        </flux:text>

        @if ($changes = $presenter->changes())
            <ul class="mt-2 space-y-1 rounded-lg border border-line bg-tray p-3 text-sm">
                @foreach ($changes as $change)
                    <li>
                        <span class="text-stone-500 dark:text-stone-400">{{ $change['label'] }}:</span>
                        <span class="line-through decoration-stone-400">{{ $change['old'] }}</span>
                        <span class="text-stone-400">→</span>
                        <span class="font-medium">{{ $change['new'] }}</span>
                    </li>
                @endforeach
            </ul>
        @endif
    </div>
</li>
