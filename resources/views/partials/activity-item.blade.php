{{-- One entry of the change history. Expects $activity; optional $showSubject to show which apartment it concerns. --}}
@php $presenter = new \App\Support\ActivityPresenter($activity); @endphp

<li class="relative flex gap-4 pb-6 last:pb-0" wire:key="activity-{{ $activity->id }}">
    <div class="flex size-9 shrink-0 items-center justify-center rounded-full bg-zinc-100 dark:bg-zinc-800">
        <flux:icon :name="$presenter->icon()" variant="mini" class="text-zinc-600 dark:text-zinc-300" />
    </div>

    <div class="min-w-0 flex-1 pt-1">
        <div class="font-medium">{{ $presenter->title() }}</div>

        @if (($showSubject ?? false) && $activity->subject)
            <flux:link :href="route('apartments.show', $activity->subject)" wire:navigate class="text-sm">{{ $activity->subject->label }}</flux:link>
        @endif

        <flux:text class="text-sm">
            {{ $presenter->causerName() }} ·
            <time datetime="{{ $activity->created_at->toIso8601String() }}" title="{{ $activity->created_at->translatedFormat('j F Y, H:i') }}">
                {{ $activity->created_at->diffForHumans() }}
            </time>
        </flux:text>

        @if ($changes = $presenter->changes())
            <ul class="mt-2 space-y-1 rounded-lg bg-zinc-50 p-3 text-sm dark:bg-zinc-800/60">
                @foreach ($changes as $change)
                    <li>
                        <span class="text-zinc-500 dark:text-zinc-400">{{ $change['label'] }}:</span>
                        <span class="line-through decoration-zinc-400">{{ $change['old'] }}</span>
                        <span class="text-zinc-400">→</span>
                        <span class="font-medium">{{ $change['new'] }}</span>
                    </li>
                @endforeach
            </ul>
        @endif
    </div>
</li>
