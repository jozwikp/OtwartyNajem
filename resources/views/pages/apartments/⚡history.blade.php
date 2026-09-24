<?php

use App\Models\Apartment;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Title;
use Livewire\Component;
use Livewire\WithPagination;
use Spatie\Activitylog\Models\Activity;

new #[Title('Historia zmian')] class extends Component {
    use WithPagination;

    public Apartment $apartment;

    public function mount(Apartment $apartment): void
    {
        $this->apartment = $apartment;
    }

    #[Computed]
    public function activities()
    {
        return Activity::query()
            ->where(fn ($query) => $query
                ->where(fn ($q) => $q->where('subject_type', $this->apartment->getMorphClass())->where('subject_id', $this->apartment->id))
                ->orWhere('properties->apartment_id', $this->apartment->id))
            ->with(['causer', 'subject'])
            ->latest('id')
            ->paginate(20);
    }
}; ?>

<div>
    @include('partials.apartment.header', ['current' => 'apartments.history'])

    <div class="mx-auto w-full max-w-5xl">

    <div class="max-w-3xl">
        <flux:text class="mb-6">{{ __('Tutaj widać wszystko, co działo się z tym mieszkaniem: kto i kiedy coś dodał, zmienił lub usunął.') }}</flux:text>

        @if ($this->activities->isEmpty())
            <flux:text class="py-10 text-center">{{ __('Na razie brak wpisów.') }}</flux:text>
        @else
            <ul>
                @foreach ($this->activities as $activity)
                    @include('partials.activity-item', ['activity' => $activity])
                @endforeach
            </ul>

            <div class="mt-6">
                {{ $this->activities->links() }}
            </div>
        @endif
    </div>
    </div>
</div>
