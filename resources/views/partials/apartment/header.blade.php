{{-- Apartment page header with tabs. Expects $apartment and $current (route name of the active tab). --}}
@php
    $tabs = [
        ['route' => 'apartments.show', 'label' => __('Informacje'), 'icon' => 'home-modern'],
        ['route' => 'apartments.owners', 'label' => __('Właściciele'), 'icon' => 'users'],
        ['route' => 'apartments.history', 'label' => __('Historia zmian'), 'icon' => 'clock'],
    ];
@endphp

<div class="mb-8">
    <flux:breadcrumbs class="mb-4">
        <flux:breadcrumbs.item :href="route('apartments.index')" wire:navigate>{{ __('Moje mieszkania') }}</flux:breadcrumbs.item>
        <flux:breadcrumbs.item>{{ $apartment->label }}</flux:breadcrumbs.item>
    </flux:breadcrumbs>

    <flux:heading size="xl" level="1">{{ $apartment->label }}</flux:heading>
    <flux:text class="mt-1 text-base">{{ $apartment->full_address }}</flux:text>

    <nav class="mt-6 flex gap-1 overflow-x-auto border-b border-zinc-200 dark:border-zinc-700" aria-label="{{ __('Sekcje mieszkania') }}">
        @foreach ($tabs as $tab)
            @php $active = $current === $tab['route']; @endphp
            <a
                href="{{ route($tab['route'], $apartment) }}"
                wire:navigate
                @class([
                    '-mb-px flex items-center gap-2 whitespace-nowrap border-b-2 px-4 py-3 text-sm font-medium transition',
                    'border-accent text-zinc-900 dark:text-white' => $active,
                    'border-transparent text-zinc-500 hover:text-zinc-800 dark:text-zinc-400 dark:hover:text-zinc-200' => ! $active,
                ])
                @if ($active) aria-current="page" @endif
            >
                <flux:icon :name="$tab['icon']" variant="mini" />
                {{ $tab['label'] }}
            </a>
        @endforeach
    </nav>
</div>
