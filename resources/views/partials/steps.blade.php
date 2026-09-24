{{-- Step indicator for wizards. Expects $steps (list of labels) and $current (1-based). --}}
<ol class="mb-8 flex items-center gap-2" aria-label="{{ __('Postęp') }}">
    @foreach ($steps as $index => $label)
        @php $number = $index + 1; @endphp
        <li class="flex flex-1 items-center gap-2" @if ($number === $current) aria-current="step" @endif>
            <span @class([
                'flex size-8 shrink-0 items-center justify-center rounded-full text-sm font-semibold',
                'bg-accent text-accent-foreground' => $number <= $current,
                'bg-zinc-100 text-zinc-500 dark:bg-zinc-800 dark:text-zinc-400' => $number > $current,
            ])>
                @if ($number < $current)
                    <flux:icon.check variant="micro" />
                @else
                    {{ $number }}
                @endif
            </span>
            <span @class([
                'hidden text-sm sm:inline',
                'font-medium text-zinc-900 dark:text-white' => $number === $current,
                'text-zinc-500 dark:text-zinc-400' => $number !== $current,
            ])>{{ $label }}</span>
            @if (! $loop->last)
                <span class="h-px flex-1 bg-zinc-200 dark:bg-zinc-700"></span>
            @endif
        </li>
    @endforeach
</ol>
