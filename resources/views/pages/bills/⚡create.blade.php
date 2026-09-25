<?php

use App\Actions\Bills\SyncBillCharge;
use App\Actions\Bills\UpdateBill;
use App\Enums\BillCategory;
use App\Enums\BillStatus;
use App\Livewire\Forms\LeaseForm;
use App\Models\Bill;
use App\Models\Lease;
use App\Support\Currencies;
use App\Support\Money;
use Carbon\CarbonImmutable;
use Flux\Flux;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Title;
use Livewire\Attributes\Url;
use Livewire\Component;

new #[Title('Dodaj rachunek ręcznie')] class extends Component {
    public int $step = 1;

    #[Url(as: 'mieszkanie', except: '')]
    public string $apartmentId = '';

    public string $category = '';
    public string $supplier = '';
    public string $invoiceNumber = '';

    public string $totalAmount = '';
    public string $periodFrom = '';
    public string $periodTo = '';
    public string $issuedOn = '';
    public string $dueOn = '';
    public bool $partial = false;
    public string $tenantAmount = '';

    public function mount(): void
    {
        if ($this->apartmentId !== '' && ! $this->apartments->contains('id', (int) $this->apartmentId)) {
            $this->apartmentId = '';
        }

        $this->issuedOn = today()->toDateString();
        $this->dueOn = today()->addDays(14)->toDateString();
        $this->periodFrom = today()->subMonthNoOverflow()->startOfMonth()->toDateString();
        $this->periodTo = today()->subMonthNoOverflow()->endOfMonth()->toDateString();
    }

    #[Computed]
    public function apartments()
    {
        return Auth::user()->apartments()->orderBy('label')->get();
    }

    /**
     * The lease the bill will be charged to (the one covering the end of the period).
     */
    #[Computed]
    public function lease(): ?Lease
    {
        if ($this->apartmentId === '' || $this->periodTo === '') {
            return null;
        }

        $bill = new Bill;
        $bill->apartment_id = (int) $this->apartmentId;
        $bill->period_from = $this->periodFrom !== '' ? CarbonImmutable::parse($this->periodFrom) : null;
        $bill->period_to = CarbonImmutable::parse($this->periodTo);
        $bill->issued_on = $this->issuedOn !== '' ? CarbonImmutable::parse($this->issuedOn) : null;

        return SyncBillCharge::leaseFor($bill)?->load('tenants');
    }

    public function currency(): string
    {
        return $this->lease?->currency ?? Currencies::DEFAULT;
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    protected function stepRules(): array
    {
        return [
            1 => [
                'apartmentId' => ['required', Rule::in($this->apartments->pluck('id')->map(fn ($id) => (string) $id))],
                'category' => ['required', Rule::enum(BillCategory::class)],
                'supplier' => ['nullable', 'string', 'max:100'],
                'invoiceNumber' => ['nullable', 'string', 'max:100'],
            ],
            2 => [
                'totalAmount' => ['required', LeaseForm::moneyRule()],
                'periodFrom' => ['required', 'date'],
                'periodTo' => ['required', 'date', 'after_or_equal:periodFrom'],
                'issuedOn' => ['required', 'date'],
                'dueOn' => ['required', 'date'],
                'tenantAmount' => $this->partial ? ['required', LeaseForm::moneyRule()] : ['nullable'],
            ],
        ];
    }

    protected function validateStep(int $step): void
    {
        $this->validate($this->stepRules()[$step], [
            'apartmentId.required' => __('Wybierz mieszkanie.'),
            'category.required' => __('Wybierz, za co jest ten rachunek.'),
            'periodTo.after_or_equal' => __('Koniec okresu nie może być przed jego początkiem.'),
        ], [
            'totalAmount' => __('kwota'), 'periodFrom' => __('początek okresu'), 'periodTo' => __('koniec okresu'),
            'issuedOn' => __('data wystawienia'), 'dueOn' => __('termin płatności'), 'tenantAmount' => __('kwota dla najemcy'),
        ]);

        if ($step === 2) {
            $total = Money::parse($this->totalAmount);

            if ($total <= 0) {
                throw ValidationException::withMessages(['totalAmount' => __('Kwota musi być większa od zera.')]);
            }
            if ($this->partial && Money::parse($this->tenantAmount) > $total) {
                throw ValidationException::withMessages(['tenantAmount' => __('Kwota dla najemcy nie może być większa niż kwota rachunku.')]);
            }
        }
    }

    public function next(): void
    {
        $this->validateStep($this->step);
        $this->step = min($this->step + 1, 3);
    }

    public function back(): void
    {
        $this->resetValidation();
        $this->step = max($this->step - 1, 1);
    }

    public function goTo(int $step): void
    {
        if ($step < $this->step) {
            $this->step = $step;
        }
    }

    public function chargedAmount(): int
    {
        return (int) Money::parse($this->partial ? $this->tenantAmount : $this->totalAmount);
    }

    public function save(UpdateBill $updateBill): void
    {
        foreach ([1, 2] as $step) {
            try {
                $this->validateStep($step);
            } catch (ValidationException $e) {
                $this->step = $step;
                throw $e;
            }
        }

        $apartment = $this->apartments->firstWhere('id', (int) $this->apartmentId);
        $this->authorize('update', $apartment);

        try {
            DB::transaction(function () use ($updateBill) {
                $bill = Bill::create(['status' => BillStatus::Review, 'created_by' => Auth::id()]);

                // Entered by the owner, so it's approved (and charged) right away.
                $updateBill->handle($bill, Auth::user(), [
                    'apartment_id' => (int) $this->apartmentId,
                    'category' => BillCategory::from($this->category),
                    'supplier' => filled($this->supplier) ? trim($this->supplier) : null,
                    'invoice_number' => filled($this->invoiceNumber) ? trim($this->invoiceNumber) : null,
                    'issued_on' => $this->issuedOn,
                    'period_from' => $this->periodFrom,
                    'period_to' => $this->periodTo,
                    'due_on' => $this->dueOn,
                    'currency' => $this->currency(),
                    'total_amount' => Money::parse($this->totalAmount),
                    'tenant_amount' => $this->chargedAmount(),
                ], approve: true);
            });
        } catch (ValidationException $e) {
            $this->addError('bill', $e->validator->errors()->first());

            return;
        }

        Flux::toast(variant: 'success', text: $this->lease
            ? __('Rachunek dodany. :names obciążono kwotą :amount.', ['names' => $this->lease->tenantNames(), 'amount' => Money::format($this->chargedAmount(), $this->currency())])
            : __('Rachunek dodany bez obciążania najemcy (brak najmu w tym okresie).'));

        $this->redirectRoute('bills.index', navigate: true);
    }
}; ?>

@php
    $currency = $this->currency();
    $fmt = fn (?int $amount) => \App\Support\Money::format((int) $amount, $currency);
@endphp

<div class="mx-auto w-full max-w-2xl">
    <flux:breadcrumbs class="mb-4">
        <flux:breadcrumbs.item :href="route('bills.index')" wire:navigate>{{ __('Rachunki') }}</flux:breadcrumbs.item>
        <flux:breadcrumbs.item>{{ __('Dodaj ręcznie') }}</flux:breadcrumbs.item>
    </flux:breadcrumbs>

    <flux:heading size="xl" level="1" class="mb-6">{{ __('Dodaj rachunek ręcznie') }}</flux:heading>

    @include('partials.steps', ['steps' => [__('Mieszkanie i rodzaj'), __('Kwota i okres'), __('Podsumowanie')], 'current' => $step])

    <flux:card class="bg-tray p-6 sm:p-8">
        @if ($step === 1)
            <form wire:submit="next" wire:key="step-1">
                <flux:heading size="lg">{{ __('Za co i dla którego mieszkania?') }}</flux:heading>
                <flux:text class="mb-6 mt-1">{{ __('Bez wgrywania pliku – wystarczą dane z rachunku.') }}</flux:text>

                <div class="space-y-6">
                    <flux:select wire:model="apartmentId" :label="__('Mieszkanie')">
                        <flux:select.option value="">{{ __('— wybierz —') }}</flux:select.option>
                        @foreach ($this->apartments as $apartment)
                            <flux:select.option :value="$apartment->id">{{ $apartment->label }}</flux:select.option>
                        @endforeach
                    </flux:select>

                    <div>
                        <flux:label>{{ __('Rodzaj rachunku') }}</flux:label>
                        <div class="mt-2 grid grid-cols-2 gap-3 sm:grid-cols-3">
                            @foreach (\App\Enums\BillCategory::cases() as $option)
                                <label @class([
                                    'flex cursor-pointer flex-col items-center gap-2 rounded-xl border p-4 text-center text-sm font-medium transition',
                                    'border-accent bg-card ring-2 ring-accent' => $category === $option->value,
                                    'border-line bg-card hover:border-accent/40' => $category !== $option->value,
                                ])>
                                    <input type="radio" wire:model.live="category" value="{{ $option->value }}" class="sr-only">
                                    <span class="flex size-10 items-center justify-center rounded-lg bg-badge">
                                        <flux:icon :name="$option->icon()" variant="mini" class="text-accent-content" />
                                    </span>
                                    {{ $option->label() }}
                                </label>
                            @endforeach
                        </div>
                        <flux:error name="category" class="mt-2" />
                    </div>

                    <div class="grid gap-4 sm:grid-cols-2">
                        <flux:input wire:model="supplier" :label="__('Dostawca')" :badge="__('Opcjonalnie')" :placeholder="__('np. Tauron, PGNiG')" />
                        <flux:input wire:model="invoiceNumber" :label="__('Numer faktury')" :badge="__('Opcjonalnie')" />
                    </div>
                </div>

                <div class="mt-8 flex justify-between gap-3">
                    <flux:button :href="route('bills.index')" wire:navigate variant="ghost">{{ __('Anuluj') }}</flux:button>
                    <flux:button type="submit" variant="primary" icon:trailing="arrow-right">{{ __('Dalej') }}</flux:button>
                </div>
            </form>
        @elseif ($step === 2)
            <form wire:submit="next" wire:key="step-2">
                <flux:heading size="lg">{{ __('Kwota i okres') }}</flux:heading>
                <flux:text class="mb-6 mt-1">{{ __('Przepisz dane z rachunku.') }}</flux:text>

                <div class="space-y-6">
                    @include('partials.money-input', ['model' => 'totalAmount', 'label' => __('Kwota do zapłaty'), 'currency' => $currency, 'autofocus' => true])

                    <div class="grid gap-4 sm:grid-cols-2">
                        <flux:input type="date" wire:model.live="periodFrom" :label="__('Za okres od')" />
                        <flux:input type="date" wire:model.live="periodTo" :label="__('do')" />
                        <flux:input type="date" wire:model="issuedOn" :label="__('Data wystawienia')" />
                        <flux:input type="date" wire:model="dueOn" :label="__('Termin płatności dla najemcy')" />
                    </div>

                    <div class="rounded-xl border border-line bg-card p-4">
                        <flux:checkbox wire:model.live="partial" :label="__('Najemca płaci tylko część tego rachunku')" :description="__('Np. gdy rachunek obejmuje też dni przed rozpoczęciem najmu.')" />
                        @if ($partial)
                            <div class="mt-4">
                                @include('partials.money-input', ['model' => 'tenantAmount', 'label' => __('Kwota dla najemcy'), 'currency' => $currency])
                            </div>
                        @endif
                    </div>
                </div>

                <div class="mt-8 flex justify-between gap-3">
                    <flux:button wire:click="back" variant="ghost" icon="arrow-left">{{ __('Wstecz') }}</flux:button>
                    <flux:button type="submit" variant="primary" icon:trailing="arrow-right">{{ __('Dalej') }}</flux:button>
                </div>
            </form>
        @else
            <div wire:key="step-3">
                <flux:heading size="lg">{{ __('Sprawdź, czy wszystko się zgadza') }}</flux:heading>

                <div class="mt-6 divide-y divide-line rounded-xl border border-line bg-card">
                    <div class="flex items-start justify-between gap-4 p-4">
                        <div>
                            <flux:text class="text-sm">{{ __('Mieszkanie i rodzaj') }}</flux:text>
                            <div class="mt-1 font-medium">
                                {{ $this->apartments->firstWhere('id', (int) $apartmentId)?->label }} ·
                                {{ \App\Enums\BillCategory::from($category)->label() }}
                                @if ($supplier) · {{ $supplier }} @endif
                                @if ($invoiceNumber) · {{ __('nr :number', ['number' => $invoiceNumber]) }} @endif
                            </div>
                        </div>
                        <flux:button size="sm" variant="ghost" wire:click="goTo(1)">{{ __('Zmień') }}</flux:button>
                    </div>
                    <div class="flex items-start justify-between gap-4 p-4">
                        <div>
                            <flux:text class="text-sm">{{ __('Kwota i okres') }}</flux:text>
                            <div class="mt-1 font-medium">{{ $fmt(\App\Support\Money::parse($totalAmount)) }}</div>
                            <flux:text class="text-sm">
                                {{ __('za :from – :to', ['from' => \Carbon\CarbonImmutable::parse($periodFrom)->format('d.m.Y'), 'to' => \Carbon\CarbonImmutable::parse($periodTo)->format('d.m.Y')]) }}
                                · {{ __('termin :date', ['date' => \Carbon\CarbonImmutable::parse($dueOn)->format('d.m.Y')]) }}
                            </flux:text>
                        </div>
                        <flux:button size="sm" variant="ghost" wire:click="goTo(2)">{{ __('Zmień') }}</flux:button>
                    </div>
                </div>

                @if ($this->lease)
                    <div class="mt-6 rounded-xl border border-accent/30 bg-badge p-4">
                        <flux:text>{{ __('Obciążymy: :names', ['names' => $this->lease->tenantNames()]) }}</flux:text>
                        <div class="text-2xl font-semibold text-accent-content">{{ $fmt($this->chargedAmount()) }}</div>
                    </div>
                @else
                    <flux:callout icon="information-circle" color="amber" class="mt-6">
                        <flux:callout.text>{{ __('W tym okresie mieszkanie nie ma najmu – rachunek zapiszemy bez obciążania najemcy.') }}</flux:callout.text>
                    </flux:callout>
                @endif

                <flux:error name="bill" class="mt-4" />

                <div class="mt-8 flex justify-between gap-3">
                    <flux:button wire:click="back" variant="ghost" icon="arrow-left">{{ __('Wstecz') }}</flux:button>
                    <flux:button wire:click="save" variant="primary" icon="check">{{ __('Dodaj rachunek') }}</flux:button>
                </div>
            </div>
        @endif
    </flux:card>
</div>
