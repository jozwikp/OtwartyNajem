<x-layouts::auth :title="__('Witaj')">
    <div class="flex flex-col gap-6 text-center">
        <div>
            <flux:heading size="xl" level="1">{{ config('app.name') }}</flux:heading>
            <flux:text class="mt-2 text-base">
                {{ __('Proste zarządzanie mieszkaniami na wynajem. Wszystkie adresy w jednym miejscu, razem ze współwłaścicielami.') }}
            </flux:text>
        </div>

        @auth
            <flux:button variant="primary" :href="route('dashboard')" wire:navigate class="w-full">{{ __('Przejdź do panelu') }}</flux:button>
        @else
            <div class="flex flex-col gap-2">
                <flux:button variant="primary" :href="route('register')" wire:navigate class="w-full">{{ __('Załóż darmowe konto') }}</flux:button>
                <flux:button :href="route('login')" wire:navigate class="w-full">{{ __('Zaloguj się') }}</flux:button>
            </div>
        @endauth
    </div>
</x-layouts::auth>
