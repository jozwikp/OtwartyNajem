<?php

use App\Actions\Bills\ApproveBill;
use App\Actions\Bills\DeleteBill;
use App\Actions\Bills\UpdateBill;
use App\Actions\Bills\UploadBills;
use App\Enums\BillStatus;
use App\Jobs\ReadBill;
use App\Livewire\Forms\LeaseForm;
use App\Models\Bill;
use App\Support\Money;
use Flux\Flux;
use Illuminate\Support\Facades\Auth;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Title;
use Livewire\Attributes\Url;
use Livewire\Component;
use Livewire\WithFileUploads;

new #[Title('Rachunki')] class extends Component {
    use WithFileUploads;

    /** @var array<int, \Livewire\Features\SupportFileUploads\TemporaryUploadedFile> */
    public array $uploads = [];

    public ?int $correctingId = null;
    public string $correctApartmentId = '';
    public string $correctAmount = '';

    #[Url(as: 'mieszkanie', except: '')]
    public string $apartmentFilter = '';

    protected function bills()
    {
        return Bill::visibleTo(Auth::user())->with(['apartment', 'lease.tenants']);
    }

    #[Computed]
    public function pending()
    {
        return $this->bills()->whereIn('status', [BillStatus::Queued, BillStatus::Processing])->oldest()->get();
    }

    #[Computed]
    public function toReview()
    {
        return $this->bills()->whereIn('status', [BillStatus::Review, BillStatus::Failed])->oldest()->get();
    }

    #[Computed]
    public function approved()
    {
        return $this->bills()
            ->where('status', BillStatus::Approved)
            ->when($this->apartmentFilter !== '', fn ($q) => $q->where('apartment_id', $this->apartmentFilter))
            ->latest('approved_at')
            ->limit(50)
            ->get();
    }

    #[Computed]
    public function apartments()
    {
        return Auth::user()->apartments()->orderBy('label')->get();
    }

    protected function findBill(int $id): Bill
    {
        $bill = $this->bills()->findOrFail($id);
        $this->authorize('update', $bill);

        return $bill;
    }

    public function updatedUploads(UploadBills $uploadBills): void
    {
        $this->validate([
            'uploads' => ['array', 'max:20'],
            'uploads.*' => ['file', 'mimes:pdf,jpg,jpeg,png,webp', 'max:10240'],
        ], [
            'uploads.max' => __('Możesz wgrać naraz najwyżej 20 plików.'),
            'uploads.*.mimes' => __('Plik :attribute nie jest PDF-em ani zdjęciem (JPG, PNG, WEBP).'),
            'uploads.*.max' => __('Plik :attribute jest większy niż 10 MB.'),
        ]);

        $bills = $uploadBills->handle(Auth::user(), $this->uploads);
        $this->reset('uploads');

        Flux::toast(variant: 'success', text: trans_choice(
            'Wysłano :count dokument do odczytu.|Wysłano :count dokumenty do odczytu.|Wysłano :count dokumentów do odczytu.',
            count($bills),
        ));
    }

    public function approve(int $id, ApproveBill $approveBill): void
    {
        $bill = $this->findBill($id);

        try {
            $approveBill->handle($bill, Auth::user());
        } catch (ValidationException $e) {
            Flux::toast(variant: 'danger', text: $e->validator->errors()->first());

            return;
        }

        Flux::toast(variant: 'success', text: $bill->lease
            ? __('Zatwierdzono. :names obciążono kwotą :amount.', ['names' => $bill->lease->tenantNames(), 'amount' => Money::format($bill->tenant_amount, $bill->currency)])
            : __('Zatwierdzono. Rachunek zapisano bez obciążania najemcy.'));
    }

    public function approveAllReady(ApproveBill $approveBill): void
    {
        $count = 0;

        foreach ($this->toReview as $bill) {
            if ($bill->status === BillStatus::Review && $bill->missingForApproval() === [] && Auth::user()->can('update', $bill)) {
                $approveBill->handle($bill, Auth::user());
                $count++;
            }
        }

        unset($this->toReview);
        Flux::toast(variant: 'success', text: trans_choice('Zatwierdzono :count rachunek.|Zatwierdzono :count rachunki.|Zatwierdzono :count rachunków.', $count));
    }

    public function openCorrection(int $id): void
    {
        $bill = $this->findBill($id);
        $this->resetValidation();

        $this->correctingId = $bill->id;
        $this->correctApartmentId = (string) $bill->apartment_id;
        $this->correctAmount = Money::toInput($bill->tenant_amount);

        Flux::modal('correct-bill')->show();
    }

    public function saveCorrection(bool $approve = false): void
    {
        $bill = $this->findBill($this->correctingId);

        $this->validate([
            'correctApartmentId' => ['required', Rule::in($this->apartments->pluck('id')->map(fn ($id) => (string) $id))],
            'correctAmount' => ['required', LeaseForm::moneyRule()],
        ], ['correctApartmentId.required' => __('Wybierz mieszkanie.')], ['correctAmount' => __('kwota')]);

        $amount = Money::parse($this->correctAmount);

        try {
            app(UpdateBill::class)->handle($bill, Auth::user(), [
                'apartment_id' => (int) $this->correctApartmentId,
                'tenant_amount' => $amount,
                'total_amount' => $bill->total_amount === null || $amount > $bill->total_amount ? $amount : $bill->total_amount,
            ], $approve);
        } catch (ValidationException $e) {
            $this->addError('correctAmount', $e->validator->errors()->first());

            return;
        }

        Flux::modal('correct-bill')->close();
        Flux::toast(variant: 'success', text: $approve ? __('Poprawiono i zatwierdzono.') : __('Poprawki zapisane.'));
        unset($this->toReview);
    }

    public function retry(int $id): void
    {
        $bill = $this->findBill($id);
        $bill->forceFill(['status' => BillStatus::Queued, 'ai_error' => null])->saveQuietly();

        ReadBill::dispatch($bill);
    }

    public function delete(int $id, DeleteBill $deleteBill): void
    {
        $deleteBill->handle($this->findBill($id));

        Flux::toast(text: __('Rachunek usunięty.'));
    }
}; ?>

@php
    $fmt = fn (?int $amount, ?string $currency) => $amount === null ? '—' : \App\Support\Money::format($amount, $currency ?? 'PLN');
    $readyCount = $this->toReview->filter(fn ($b) => $b->status === \App\Enums\BillStatus::Review && $b->missingForApproval() === [])->count();
@endphp

<div class="mx-auto w-full max-w-5xl" @if ($this->pending->isNotEmpty()) wire:poll.3s @endif>
    <div class="mb-8">
        <flux:heading size="xl" level="1">{{ __('Rachunki') }}</flux:heading>
        <flux:text class="mt-1 text-base">{{ __('Wgraj faktury za media – sami odczytamy kwoty, daty i dopasujemy je do mieszkań. Ty tylko zatwierdzasz.') }}</flux:text>
    </div>

    {{-- Upload --}}
    <label class="relative flex cursor-pointer flex-col items-center justify-center rounded-2xl border-2 border-dashed border-stone-300 bg-tray px-6 py-10 text-center transition hover:border-accent hover:bg-card dark:border-stone-700">
        <div class="mb-3 flex size-14 items-center justify-center rounded-full bg-badge">
            <flux:icon.arrow-up-tray class="size-7 text-accent-content" />
        </div>
        <span class="text-lg font-semibold">{{ __('Wybierz pliki faktur') }}</span>
        <flux:text class="mt-1">{{ __('PDF albo zdjęcia, możesz zaznaczyć kilka naraz (do 20 plików, każdy do 10 MB).') }}</flux:text>
        <input type="file" wire:model="uploads" multiple accept=".pdf,image/jpeg,image/png,image/webp" class="absolute inset-0 cursor-pointer opacity-0">

        <div wire:loading.flex wire:target="uploads" class="absolute inset-0 items-center justify-center gap-2 rounded-2xl bg-card/90 font-medium">
            <flux:icon.arrow-path class="animate-spin" /> {{ __('Wysyłanie plików…') }}
        </div>
    </label>
    @error('uploads') <flux:text class="mt-2 text-red-600!">{{ $message }}</flux:text> @enderror
    @error('uploads.*') <flux:text class="mt-2 text-red-600!">{{ $message }}</flux:text> @enderror

    {{-- Being read --}}
    @if ($this->pending->isNotEmpty())
        <section class="mt-8">
            <flux:heading size="lg" class="mb-3">{{ __('Odczytujemy…') }} ({{ $this->pending->count() }})</flux:heading>
            <ul class="divide-y divide-line rounded-2xl border border-line bg-card">
                @foreach ($this->pending as $bill)
                    <li class="flex items-center gap-3 px-4 py-3" wire:key="pending-{{ $bill->id }}">
                        <flux:icon.arrow-path class="size-5 animate-spin text-accent-content" />
                        <span class="min-w-0 flex-1 truncate">{{ $bill->file_name }}</span>
                        <flux:text class="text-sm">{{ $bill->status->label() }}</flux:text>
                    </li>
                @endforeach
            </ul>
            <flux:text class="mt-2 text-sm">{{ __('Zwykle trwa to kilkanaście sekund na dokument. Możesz zostać na tej stronie albo wrócić później.') }}</flux:text>
            @if (app()->isLocal() && $this->pending->contains(fn ($b) => $b->status === \App\Enums\BillStatus::Queued && $b->created_at->lt(now()->subMinute())))
                <flux:callout icon="exclamation-triangle" color="amber" class="mt-3">
                    <flux:callout.text>{{ __('Odczyt nie wystartował od ponad minuty. Sprawdź, czy działa kolejka (composer run dev albo php artisan queue:work).') }}</flux:callout.text>
                </flux:callout>
            @endif
        </section>
    @endif

    {{-- To review --}}
    @if ($this->toReview->isNotEmpty())
        <section class="mt-10">
            <div class="mb-3 flex flex-wrap items-center justify-between gap-3">
                <flux:heading size="lg">{{ __('Do sprawdzenia') }} ({{ $this->toReview->count() }})</flux:heading>
                @if ($readyCount > 1)
                    <flux:button variant="primary" icon="check" wire:click="approveAllReady" wire:confirm="{{ __('Zatwierdzić wszystkie gotowe rachunki (:count)?', ['count' => $readyCount]) }}">
                        {{ __('Zatwierdź wszystkie gotowe (:count)', ['count' => $readyCount]) }}
                    </flux:button>
                @endif
            </div>

            <div class="space-y-4">
                @foreach ($this->toReview as $bill)
                    @php
                        $confidence = $bill->aiValue('match_confidence');
                        $missing = $bill->missingForApproval();
                    @endphp
                    <article class="rounded-2xl border border-line bg-card p-5" wire:key="review-{{ $bill->id }}">
                        @if ($bill->status === \App\Enums\BillStatus::Failed)
                            <div class="flex flex-wrap items-start gap-4">
                                <div class="flex size-12 shrink-0 items-center justify-center rounded-xl bg-red-50 dark:bg-red-950/40">
                                    <flux:icon.exclamation-triangle class="text-red-600" />
                                </div>
                                <div class="min-w-0 flex-1">
                                    <div class="font-semibold">{{ __('Nie udało się odczytać dokumentu') }}</div>
                                    <flux:link :href="route('bills.file', $bill)" target="_blank" class="text-sm">{{ $bill->file_name }}</flux:link>
                                    <flux:text class="mt-1 text-sm">{{ $bill->ai_error }}</flux:text>
                                </div>
                                <div class="flex flex-wrap gap-2">
                                    <flux:button size="sm" icon="arrow-path" wire:click="retry({{ $bill->id }})">{{ __('Spróbuj ponownie') }}</flux:button>
                                    <flux:button size="sm" icon="pencil-square" :href="route('bills.edit', $bill)" wire:navigate>{{ __('Uzupełnij ręcznie') }}</flux:button>
                                    <flux:button size="sm" variant="ghost" icon="trash" wire:click="delete({{ $bill->id }})" wire:confirm="{{ __('Usunąć ten dokument?') }}" />
                                </div>
                            </div>
                        @else
                            <div class="flex flex-wrap items-start gap-4">
                                <a href="{{ route('bills.file', $bill) }}" target="_blank" class="flex size-12 shrink-0 items-center justify-center overflow-hidden rounded-xl bg-badge" title="{{ __('Otwórz fakturę') }}">
                                    @if ($bill->isImage())
                                        <img src="{{ route('bills.file', $bill) }}" alt="" class="size-full object-cover">
                                    @else
                                        <flux:icon :name="$bill->category?->icon() ?? 'document-text'" class="text-accent-content" />
                                    @endif
                                </a>

                                <div class="min-w-0 flex-1 space-y-1">
                                    <div class="flex flex-wrap items-baseline gap-x-3">
                                        <span class="text-lg font-semibold">{{ $bill->category?->label() ?? __('Rachunek') }}@if ($bill->supplier) · {{ $bill->supplier }}@endif</span>
                                        <span class="text-2xl font-semibold">{{ $fmt($bill->tenant_amount, $bill->currency) }}</span>
                                        @if ($bill->isPartial())
                                            <flux:text class="text-sm">{{ __('z :total na fakturze', ['total' => $fmt($bill->total_amount, $bill->currency)]) }}</flux:text>
                                        @endif
                                    </div>

                                    <div class="flex flex-wrap items-center gap-2">
                                        <flux:icon.home-modern variant="micro" class="text-stone-400" />
                                        @if ($bill->apartment)
                                            <span class="font-medium">{{ $bill->apartment->label }}</span>
                                            @if ($confidence === 'high')
                                                <flux:badge size="sm" color="green">{{ __('pewne dopasowanie') }}</flux:badge>
                                            @elseif ($confidence === 'medium')
                                                <flux:badge size="sm" color="amber">{{ __('prawdopodobne') }}</flux:badge>
                                            @elseif ($confidence === 'low')
                                                <flux:badge size="sm" color="red">{{ __('niepewne – sprawdź') }}</flux:badge>
                                            @endif
                                        @else
                                            <span class="font-medium text-amber-700 dark:text-amber-400">{{ __('Nie rozpoznano mieszkania') }}</span>
                                        @endif
                                    </div>
                                    @if ($bill->aiValue('address_on_invoice'))
                                        <flux:text class="text-sm">{{ __('Adres na fakturze: :address', ['address' => $bill->aiValue('address_on_invoice')]) }}</flux:text>
                                    @endif

                                    <flux:text class="text-sm">
                                        @if ($bill->lease)
                                            {{ __('Obciąży: :names', ['names' => $bill->lease->tenantNames()]) }}
                                        @elseif ($bill->apartment)
                                            {{ __('Brak najmu w tym okresie – rachunek zapiszemy bez obciążania najemcy.') }}
                                        @endif
                                    </flux:text>

                                    <flux:text class="text-sm">
                                        @if ($bill->period_from && $bill->period_to) {{ __('Okres :from – :to', ['from' => $bill->period_from->format('d.m.Y'), 'to' => $bill->period_to->format('d.m.Y')]) }} @endif
                                        @if ($bill->due_on) · {{ __('termin :date', ['date' => $bill->due_on->format('d.m.Y')]) }} @endif
                                        @if ($bill->invoice_number) · {{ __('nr :number', ['number' => $bill->invoice_number]) }} @endif
                                    </flux:text>

                                    @if ($bill->aiValue('notes'))
                                        <flux:text class="text-sm italic">{{ $bill->aiValue('notes') }}</flux:text>
                                    @endif

                                    @if ($missing)
                                        <div class="mt-2 rounded-lg border border-amber-200 bg-amber-50 p-2 text-sm text-amber-800 dark:border-amber-900/60 dark:bg-amber-950/30 dark:text-amber-300">
                                            {{ implode(' ', $missing) }}
                                        </div>
                                    @endif
                                </div>

                                <div class="flex w-full flex-row gap-2 sm:w-auto sm:flex-col">
                                    <flux:button variant="primary" icon="check" wire:click="approve({{ $bill->id }})" :disabled="$missing !== []" class="flex-1">{{ __('Zatwierdź') }}</flux:button>
                                    <flux:button icon="pencil" wire:click="openCorrection({{ $bill->id }})" class="flex-1">{{ __('Popraw') }}</flux:button>
                                    <flux:dropdown align="end">
                                        <flux:button variant="ghost" size="sm" icon="ellipsis-horizontal" :aria-label="__('Więcej')" />
                                        <flux:menu>
                                            <flux:menu.item icon="document-magnifying-glass" :href="route('bills.file', $bill)" target="_blank">{{ __('Otwórz fakturę') }}</flux:menu.item>
                                            <flux:menu.item icon="pencil-square" :href="route('bills.edit', $bill)" wire:navigate>{{ __('Edytuj wszystkie dane') }}</flux:menu.item>
                                            <flux:menu.item icon="trash" variant="danger" wire:click="delete({{ $bill->id }})" wire:confirm="{{ __('Usunąć ten rachunek?') }}">{{ __('Usuń') }}</flux:menu.item>
                                        </flux:menu>
                                    </flux:dropdown>
                                </div>
                            </div>
                        @endif
                    </article>
                @endforeach
            </div>
        </section>
    @endif

    {{-- Approved --}}
    <section class="mt-10">
        <div class="mb-3 flex flex-wrap items-center justify-between gap-3">
            <flux:heading size="lg">{{ __('Zatwierdzone rachunki') }}</flux:heading>
            @if ($this->apartments->count() > 1)
                <flux:select wire:model.live="apartmentFilter" class="max-w-64" size="sm">
                    <flux:select.option value="">{{ __('Wszystkie mieszkania') }}</flux:select.option>
                    @foreach ($this->apartments as $apartment)
                        <flux:select.option :value="$apartment->id">{{ $apartment->label }}</flux:select.option>
                    @endforeach
                </flux:select>
            @endif
        </div>

        @if ($this->approved->isEmpty())
            <flux:text>{{ __('Nie ma jeszcze zatwierdzonych rachunków.') }}</flux:text>
        @else
            <ul class="divide-y divide-line rounded-2xl border border-line bg-card">
                @foreach ($this->approved as $bill)
                    <li wire:key="approved-{{ $bill->id }}">
                        <a href="{{ route('bills.edit', $bill) }}" wire:navigate class="flex flex-wrap items-center gap-3 px-4 py-3 hover:bg-tray">
                            <span class="flex size-8 shrink-0 items-center justify-center rounded-lg bg-badge">
                                <flux:icon :name="$bill->category?->icon() ?? 'document-text'" variant="micro" class="text-accent-content" />
                            </span>
                            <div class="min-w-0 flex-1">
                                <div class="text-sm font-medium">{{ $bill->category?->label() }}@if ($bill->supplier) · {{ $bill->supplier }}@endif</div>
                                <flux:text class="text-xs">
                                    {{ $bill->apartment?->label }}
                                    @if ($bill->period_from && $bill->period_to) · {{ $bill->period_from->format('d.m') }}–{{ $bill->period_to->format('d.m.Y') }} @endif
                                    · {{ $bill->lease ? $bill->lease->tenantNames() : __('bez obciążenia najemcy') }}
                                </flux:text>
                            </div>
                            <span class="font-semibold">{{ $fmt($bill->tenant_amount, $bill->currency) }}</span>
                            <flux:icon.chevron-right variant="mini" class="text-stone-400" />
                        </a>
                    </li>
                @endforeach
            </ul>
        @endif
    </section>

    {{-- Quick correction --}}
    <flux:modal name="correct-bill" class="w-full max-w-md">
        @if ($correctingId && ($correcting = $this->toReview->firstWhere('id', $correctingId)))
            <form wire:submit="saveCorrection(true)" class="space-y-6">
                <div>
                    <flux:heading size="lg">{{ __('Popraw rachunek') }}</flux:heading>
                    <flux:text class="mt-2">
                        {{ $correcting->category?->label() }}@if ($correcting->supplier) · {{ $correcting->supplier }}@endif ·
                        <flux:link :href="route('bills.file', $correcting)" target="_blank">{{ __('otwórz fakturę') }}</flux:link>
                    </flux:text>
                </div>

                <flux:select wire:model="correctApartmentId" :label="__('Mieszkanie')">
                    <flux:select.option value="">{{ __('— wybierz —') }}</flux:select.option>
                    @foreach ($this->apartments as $apartment)
                        <flux:select.option :value="$apartment->id">{{ $apartment->label }}</flux:select.option>
                    @endforeach
                </flux:select>

                @include('partials.money-input', [
                    'model' => 'correctAmount',
                    'label' => __('Kwota dla najemcy'),
                    'currency' => $correcting->currency ?? 'PLN',
                    'description' => $correcting->total_amount !== null ? __('Na fakturze: :total. Wpisz mniej, jeśli najemca płaci tylko część.', ['total' => $fmt($correcting->total_amount, $correcting->currency)]) : null,
                ])

                <flux:link :href="route('bills.edit', $correcting)" wire:navigate class="text-sm">{{ __('Zmień inne dane (daty, rodzaj, dostawca) →') }}</flux:link>

                <div class="flex flex-wrap justify-end gap-2">
                    <flux:button variant="ghost" wire:click="saveCorrection(false)">{{ __('Zapisz') }}</flux:button>
                    <flux:button type="submit" variant="primary" icon="check">{{ __('Zapisz i zatwierdź') }}</flux:button>
                </div>
            </form>
        @endif
    </flux:modal>
</div>
