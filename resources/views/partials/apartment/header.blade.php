{{-- Apartment page header with tabs. Expects $apartment and $current (route name of the active tab). --}}
@php
    $tabs = [
        ['route' => 'apartments.show', 'label' => __('Informacje'), 'icon' => 'home-modern'],
        ['route' => 'apartments.owners', 'label' => __('Właściciele'), 'icon' => 'users'],
        ['route' => 'apartments.history', 'label' => __('Historia zmian'), 'icon' => 'clock'],
    ];
@endphp

<div class="-mx-6 -mt-6 mb-8 border-b border-line bg-tray px-6 pt-6 lg:-mx-8 lg:-mt-8 lg:px-8 lg:pt-8">
<div class="mx-auto w-full max-w-5xl">
    <flux:breadcrumbs class="mb-4">
        <flux:breadcrumbs.item :href="route('apartments.index')" wire:navigate>{{ __('Moje mieszkania') }}</flux:breadcrumbs.item>
        <flux:breadcrumbs.item>{{ $apartment->label }}</flux:breadcrumbs.item>
    </flux:breadcrumbs>

    <div class="flex items-center gap-4">
        <div class="flex size-12 shrink-0 items-center justify-center rounded-xl bg-badge">
            <flux:icon.home-modern class="size-6 text-accent-content" />
        </div>
        <flux:heading size="xl" level="1" class="min-w-0">{{ $apartment->label }}</flux:heading>
    </div>

    <nav class="relative -mb-px mt-6 flex gap-1 overflow-x-auto overflow-y-hidden" aria-label="{{ __('Sekcje mieszkania') }}">
        @foreach ($tabs as $tab)
            @php $active = $current === $tab['route']; @endphp
            <a
                href="{{ route($tab['route'], $apartment) }}"
                wire:navigate
                @class([
                    'flex items-center gap-2 whitespace-nowrap border-b-2 px-4 py-3 text-sm font-medium transition',
                    'border-accent text-ink' => $active,
                    'border-transparent text-stone-500 hover:text-ink dark:text-stone-400' => ! $active,
                ])
                @if ($active) aria-current="page" @endif
            >
                <flux:icon :name="$tab['icon']" variant="mini" />
                {{ $tab['label'] }}
            </a>
        @endforeach
    </nav>
</div>
</div>
