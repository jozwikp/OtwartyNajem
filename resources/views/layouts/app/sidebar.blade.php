<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}" class="dark">
    <head>
        @include('partials.head')
    </head>
    <body class="min-h-screen bg-paper text-ink">
        @php
            $sidebarLimit = 12;
            $sidebarApartments = auth()->user()->apartments()->orderBy('label')->limit($sidebarLimit + 1)->get(['apartments.id', 'apartments.label']);
            $currentApartmentId = optional(request()->route('apartment'))->id;
            $billsToReview = \App\Models\Bill::visibleTo(auth()->user())->whereIn('status', ['review', 'failed'])->count();
        @endphp

        <flux:sidebar sticky collapsible="mobile" class="border-e border-line bg-rail">
            <flux:sidebar.header>
                <x-app-logo :sidebar="true" href="{{ route('dashboard') }}" wire:navigate />
                <flux:sidebar.collapse class="lg:hidden" />
            </flux:sidebar.header>

            <flux:sidebar.nav>
                <flux:sidebar.item icon="home" :href="route('dashboard')" :current="request()->routeIs('dashboard')" wire:navigate>
                    {{ __('Pulpit') }}
                </flux:sidebar.item>

                <flux:sidebar.item icon="building-office-2" :href="route('apartments.index')" :current="request()->routeIs('apartments.index')" wire:navigate>
                    {{ __('Wszystkie mieszkania') }}
                </flux:sidebar.item>

                <flux:sidebar.item icon="receipt-percent" :href="route('bills.index')" :current="request()->routeIs('bills.*')" :badge="$billsToReview ?: null" badge:color="amber" wire:navigate>
                    {{ __('Rachunki') }}
                </flux:sidebar.item>
            </flux:sidebar.nav>

            <flux:sidebar.group :heading="__('Moje mieszkania')" class="grid">
                @foreach ($sidebarApartments->take($sidebarLimit) as $sidebarApartment)
                    @php $isCurrentApartment = $currentApartmentId === $sidebarApartment->id; @endphp
                    <a
                        href="{{ route('apartments.show', $sidebarApartment) }}"
                        wire:navigate
                        title="{{ $sidebarApartment->label }}"
                        @if ($isCurrentApartment) aria-current="page" @endif
                        @class([
                            'my-px flex h-9 items-center gap-2.5 rounded-lg px-2 text-sm transition',
                            'bg-card font-medium text-ink shadow-xs ring-1 ring-line' => $isCurrentApartment,
                            'text-stone-600 hover:bg-stone-800/5 hover:text-ink dark:text-stone-300 dark:hover:bg-white/5' => ! $isCurrentApartment,
                        ])
                    >
                        <span class="flex size-6 shrink-0 items-center justify-center rounded-md bg-badge">
                            <flux:icon.home-modern variant="micro" class="text-accent-content" />
                        </span>
                        <span class="truncate">{{ $sidebarApartment->label }}</span>
                    </a>
                @endforeach

                @if ($sidebarApartments->count() > $sidebarLimit)
                    <a href="{{ route('apartments.index') }}" wire:navigate class="my-px flex h-8 items-center px-2 text-sm text-accent-content hover:underline">
                        {{ __('Pokaż wszystkie') }} →
                    </a>
                @endif

                <a
                    href="{{ route('apartments.create') }}"
                    wire:navigate
                    @class([
                        'my-px flex h-9 items-center gap-2.5 rounded-lg px-2 text-sm transition',
                        'bg-card font-medium text-ink ring-1 ring-line' => request()->routeIs('apartments.create'),
                        'text-stone-500 hover:bg-stone-800/5 hover:text-ink dark:text-stone-400 dark:hover:bg-white/5' => ! request()->routeIs('apartments.create'),
                    ])
                >
                    <span class="flex size-6 shrink-0 items-center justify-center rounded-md border border-dashed border-stone-300 dark:border-stone-600">
                        <flux:icon.plus variant="micro" />
                    </span>
                    {{ __('Dodaj mieszkanie') }}
                </a>
            </flux:sidebar.group>

            <flux:spacer />

            <x-desktop-user-menu class="hidden lg:block" :name="auth()->user()->name" />
        </flux:sidebar>

        <!-- Mobile User Menu -->
        <flux:header class="border-b border-line bg-rail lg:hidden">
            <flux:sidebar.toggle class="lg:hidden" icon="bars-2" inset="left" />

            <flux:spacer />

            <flux:dropdown position="top" align="end">
                <flux:profile
                    :initials="auth()->user()->initials()"
                    icon-trailing="chevron-down"
                />

                <flux:menu>
                    <flux:menu.radio.group>
                        <div class="p-0 text-sm font-normal">
                            <div class="flex items-center gap-2 px-1 py-1.5 text-start text-sm">
                                <flux:avatar
                                    :name="auth()->user()->name"
                                    :initials="auth()->user()->initials()"
                                />

                                <div class="grid flex-1 text-start text-sm leading-tight">
                                    <flux:heading class="truncate">{{ auth()->user()->name }}</flux:heading>
                                    <flux:text class="truncate">{{ auth()->user()->email }}</flux:text>
                                </div>
                            </div>
                        </div>
                    </flux:menu.radio.group>

                    <flux:menu.separator />

                    <flux:menu.radio.group>
                        <flux:menu.item :href="route('profile.edit')" icon="cog" wire:navigate>
                            {{ __('Settings') }}
                        </flux:menu.item>
                    </flux:menu.radio.group>

                    <flux:menu.separator />

                    <form method="POST" action="{{ route('logout') }}" class="w-full">
                        @csrf
                        <flux:menu.item
                            as="button"
                            type="submit"
                            icon="arrow-right-start-on-rectangle"
                            class="w-full cursor-pointer"
                            data-test="logout-button"
                        >
                            {{ __('Log out') }}
                        </flux:menu.item>
                    </form>
                </flux:menu>
            </flux:dropdown>
        </flux:header>

        {{ $slot }}

        @persist('toast')
            <flux:toast.group>
                <flux:toast />
            </flux:toast.group>
        @endpersist

        @fluxScripts
    </body>
</html>
