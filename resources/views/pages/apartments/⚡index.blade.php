<?php

use App\Models\Apartment;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\Auth;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Title;
use Livewire\Attributes\Url;
use Livewire\Component;

new #[Title('Moje mieszkania')] class extends Component {
    #[Url(as: 'szukaj', except: '')]
    public string $search = '';

    /**
     * @return Collection<int, Apartment>
     */
    #[Computed]
    public function apartments(): Collection
    {
        return Apartment::ownedBy(Auth::user())
            ->with('owners')
            ->when($this->search !== '', function ($query) {
                $term = '%'.$this->search.'%';
                $query->where(fn ($q) => $q
                    ->where('label', 'like', $term)
                    ->orWhere('street', 'like', $term)
                    ->orWhere('city', 'like', $term)
                    ->orWhere('postal_code', 'like', $term));
            })
            ->orderBy('label')
            ->get();
    }

    #[Computed]
    public function total(): int
    {
        return Apartment::ownedBy(Auth::user())->count();
    }
}; ?>

<div class="mx-auto w-full max-w-5xl">
    <div class="mb-8 flex flex-wrap items-end justify-between gap-4">
        <div>
            <flux:heading size="xl" level="1">{{ __('Moje mieszkania') }}</flux:heading>
            <flux:text class="mt-1 text-base">{{ __('Wszystkie mieszkania, którymi zarządzasz.') }}</flux:text>
        </div>

        @if ($this->total > 0)
            <flux:button variant="primary" icon="plus" :href="route('apartments.create')" wire:navigate>
                {{ __('Dodaj mieszkanie') }}
            </flux:button>
        @endif
    </div>

    @if ($this->total === 0)
        <div class="flex flex-col items-center rounded-2xl border-2 border-dashed border-stone-300 bg-tray px-6 py-16 text-center dark:border-stone-700">
            <div class="mb-4 flex size-16 items-center justify-center rounded-full bg-badge">
                <flux:icon.home-modern class="size-8 text-accent-content" />
            </div>
            <flux:heading size="lg">{{ __('Nie masz jeszcze żadnych mieszkań') }}</flux:heading>
            <flux:text class="mt-2 max-w-md text-base">
                {{ __('Dodaj pierwsze mieszkanie. Zajmie to mniej niż minutę – potrzebujesz tylko adresu i metrażu.') }}
            </flux:text>
            <flux:button variant="primary" icon="plus" class="mt-6" :href="route('apartments.create')" wire:navigate>
                {{ __('Dodaj pierwsze mieszkanie') }}
            </flux:button>
        </div>
    @else
        @if ($this->total > 6)
            <flux:input
                wire:model.live.debounce.300ms="search"
                icon="magnifying-glass"
                :placeholder="__('Szukaj po nazwie, ulicy lub mieście…')"
                clearable
                class="mb-6 max-w-md"
            />
        @endif

        @if ($this->apartments->isEmpty())
            <flux:text class="py-10 text-center text-base">{{ __('Nie znaleziono mieszkań pasujących do „:search”.', ['search' => $search]) }}</flux:text>
        @endif

        <div class="grid gap-4 sm:grid-cols-2 lg:grid-cols-3">
            @foreach ($this->apartments as $apartment)
                <a
                    href="{{ route('apartments.show', $apartment) }}"
                    wire:navigate
                    wire:key="apartment-{{ $apartment->id }}"
                    class="group flex flex-col rounded-2xl border border-line bg-card p-5 transition hover:border-accent/40 hover:shadow-md"
                >
                    <div class="mb-3 flex size-10 items-center justify-center rounded-xl bg-badge">
                        <flux:icon.home-modern variant="mini" class="text-accent-content" />
                    </div>

                    <flux:heading size="lg" class="group-hover:underline">{{ $apartment->label }}</flux:heading>
                    <flux:text class="mt-1">{{ $apartment->address_line }}</flux:text>
                    <flux:text>{{ $apartment->postal_code }} {{ $apartment->city }}</flux:text>

                    <div class="mt-auto flex items-center justify-between pt-4">
                        <flux:badge size="sm">{{ $apartment->area_formatted }}</flux:badge>

                        @if ($apartment->owners->count() > 1)
                            <flux:text class="flex items-center gap-1 text-sm">
                                <flux:icon.users variant="micro" />
                                {{ trans_choice(':count właściciel|:count właścicieli|:count właścicieli', $apartment->owners->count()) }}
                            </flux:text>
                        @endif
                    </div>
                </a>
            @endforeach
        </div>
    @endif
</div>
