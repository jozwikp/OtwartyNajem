<x-layouts::auth :title="__('Email verification')">
    <div class="flex flex-col gap-6">
        <x-auth-header
            :title="__('Verify your email address')"
            :description="__('Please verify your email address by clicking on the link we just emailed to you.')"
        />

        <flux:text class="text-center">
            {{ __('Link wysłaliśmy na adres :email. Nie widzisz wiadomości? Zajrzyj do folderu spam.', ['email' => auth()->user()->email]) }}
        </flux:text>

        @if (session('status') == 'verification-link-sent')
            <flux:text class="text-center font-medium !text-green-600 !dark:text-green-400">
                {{ __('A new verification link has been sent to the email address you provided during registration.') }}
            </flux:text>
        @endif

        <div class="flex flex-col gap-2">
            <form method="POST" action="{{ route('verification.send') }}">
                @csrf
                <flux:button type="submit" variant="primary" class="w-full" data-test="resend-verification-button">
                    {{ __('Resend verification email') }}
                </flux:button>
            </form>

            <flux:button :href="route('profile.edit')" class="w-full" wire:navigate>
                {{ __('Popraw adres e-mail') }}
            </flux:button>

            <form method="POST" action="{{ route('logout') }}">
                @csrf
                <flux:button type="submit" variant="ghost" class="w-full" data-test="logout-button">
                    {{ __('Log out') }}
                </flux:button>
            </form>
        </div>
    </div>
</x-layouts::auth>
