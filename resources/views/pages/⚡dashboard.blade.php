<?php

use App\Models\Apartment;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Number;
use Illuminate\Support\Str;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Title;
use Livewire\Component;
use App\Models\Activity;

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
        $apartmentIds = Auth::user()->apartments()->pluck('apartments.id');

        return Activity::query()
            ->where(fn ($query) => $query
                ->where(fn ($q) => $q->where('subject_type', (new Apartment)->getMorphClass())->whereIn('subject_id', $apartmentIds))
                ->orWhereIn('apartment_id', $apartmentIds))
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

    @php $billsToReview = \App\Models\Bill::visibleTo(auth()->user())->whereIn('status', ['review', 'failed'])->count(); @endphp
    @if ($billsToReview > 0)
        <a href="{{ route('bills.index') }}" wire:navigate class="mt-6 flex items-center gap-3 rounded-2xl border border-amber-200 bg-amber-50 p-4 font-medium text-amber-900 transition hover:shadow-md dark:border-amber-900/60 dark:bg-amber-950/30 dark:text-amber-200">
            <flux:icon.receipt-percent />
            <span class="flex-1">{{ trans_choice(':count rachunek czeka na sprawdzenie|:count rachunki czekają na sprawdzenie|:count rachunków czeka na sprawdzenie', $billsToReview) }}</span>
            <flux:icon.arrow-right variant="mini" />
        </a>
    @endif

    @if ($this->apartmentsCount === 0)
        <div class="mt-8 flex flex-col items-center rounded-2xl border-2 border-dashed border-stone-300 bg-tray px-6 py-16 text-center dark:border-stone-700">
            <div class="mb-4 flex size-16 items-center justify-center rounded-full bg-badge">
                <flux:icon.home-modern class="size-8 text-accent-content" />
            </div>
            <flux:heading size="lg">{{ __('Zacznij od dodania mieszkania') }}</flux:heading>
            <flux:text class="mt-2 max-w-md text-base">{{ __('Wystarczy adres i metraż. Później możesz zaprosić współwłaścicieli.') }}</flux:text>
            <flux:button variant="primary" icon="plus" class="mt-6" :href="route('apartments.create')" wire:navigate>
                {{ __('Dodaj pierwsze mieszkanie') }}
            </flux:button>
        </div>
    @else
        <div class="mt-8 grid gap-4 sm:grid-cols-3">
            <a href="{{ route('apartments.index') }}" wire:navigate class="rounded-2xl border border-line bg-card p-5 transition hover:border-accent/40 hover:shadow-md">
                <flux:text>{{ __('Mieszkania') }}</flux:text>
                <div class="mt-1 text-3xl font-semibold">{{ $this->apartmentsCount }}</div>
            </a>
            <div class="rounded-2xl border border-line bg-card p-5">
                <flux:text>{{ __('Łączny metraż') }}</flux:text>
                <div class="mt-1 text-3xl font-semibold">{{ $this->totalArea }}</div>
            </div>
            <a href="{{ route('apartments.create') }}" wire:navigate class="flex items-center justify-center gap-2 rounded-2xl border-2 border-dashed border-stone-300 p-5 font-medium text-accent-content transition hover:border-accent hover:bg-card dark:border-stone-700">
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
