<?php

use App\Models\Apartment;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Number;
use Illuminate\Support\Str;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Title;
use Livewire\Component;
use Spatie\Activitylog\Models\Activity;

new #[Title('Pulpit')] class extends Component {
    #[Computed]
    public function apartmentsCount(): int
    {
        return Apartment::ownedBy(Auth::user())->count();
    }

    #[Computed]
    public function totalArea(): string
    {
        $area = (float) Apartment::ownedBy(Auth::user())->sum('area');

        return Number::format($area, maxPrecision: 2, locale: 'pl').' m²';
    }

    #[Computed]
    public function recentActivity()
    {
        return Activity::query()
            ->where('subject_type', (new Apartment)->getMorphClass())
            ->whereIn('subject_id', Auth::user()->apartments()->select('apartments.id'))
            ->with(['causer', 'subject'])
            ->latest('id')
            ->limit(8)
            ->get();
    }

    public function firstName(): string
    {
        return Str::before(Auth::user()->name, ' ');
    }
}; ?>

<div class="mx-auto w-full max-w-5xl">
    <flux:heading size="xl" level="1">{{ __('Dzień dobry, :name!', ['name' => $this->firstName()]) }}</flux:heading>
    <flux:text class="mt-1 text-base">{{ __('Oto krótkie podsumowanie Twoich mieszkań.') }}</flux:text>

    @if ($this->apartmentsCount === 0)
        <div class="mt-8 flex flex-col items-center rounded-2xl border-2 border-dashed border-zinc-200 px-6 py-16 text-center dark:border-zinc-700">
            <div class="mb-4 flex size-16 items-center justify-center rounded-full bg-zinc-100 dark:bg-zinc-800">
                <flux:icon.home-modern class="size-8 text-zinc-500" />
            </div>
            <flux:heading size="lg">{{ __('Zacznij od dodania mieszkania') }}</flux:heading>
            <flux:text class="mt-2 max-w-md text-base">{{ __('Wystarczy adres i metraż. Później możesz zaprosić współwłaścicieli.') }}</flux:text>
            <flux:button variant="primary" icon="plus" class="mt-6" :href="route('apartments.create')" wire:navigate>
                {{ __('Dodaj pierwsze mieszkanie') }}
            </flux:button>
        </div>
    @else
        <div class="mt-8 grid gap-4 sm:grid-cols-3">
            <a href="{{ route('apartments.index') }}" wire:navigate class="rounded-2xl border border-zinc-200 p-5 transition hover:shadow-md dark:border-zinc-700">
                <flux:text>{{ __('Mieszkania') }}</flux:text>
                <div class="mt-1 text-3xl font-semibold">{{ $this->apartmentsCount }}</div>
            </a>
            <div class="rounded-2xl border border-zinc-200 p-5 dark:border-zinc-700">
                <flux:text>{{ __('Łączny metraż') }}</flux:text>
                <div class="mt-1 text-3xl font-semibold">{{ $this->totalArea }}</div>
            </div>
            <a href="{{ route('apartments.create') }}" wire:navigate class="flex items-center justify-center gap-2 rounded-2xl border-2 border-dashed border-zinc-200 p-5 font-medium text-zinc-600 transition hover:border-zinc-400 hover:text-zinc-900 dark:border-zinc-700 dark:text-zinc-300 dark:hover:text-white">
                <flux:icon.plus variant="mini" />
                {{ __('Dodaj mieszkanie') }}
            </a>
        </div>

        <section class="mt-10 max-w-3xl">
            <flux:heading size="lg" class="mb-4">{{ __('Ostatnie zmiany') }}</flux:heading>

            @if ($this->recentActivity->isEmpty())
                <flux:text>{{ __('Na razie brak wpisów.') }}</flux:text>
            @else
                <ul>
                    @foreach ($this->recentActivity as $activity)
                        @include('partials.activity-item', ['activity' => $activity, 'showSubject' => true])
                    @endforeach
                </ul>
            @endif
        </section>
    @endif
</div>
