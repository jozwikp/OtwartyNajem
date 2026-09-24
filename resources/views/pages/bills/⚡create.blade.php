<?php

use App\Actions\Leases\CreateBill;
use App\Enums\BillCategory;
use App\Livewire\Forms\LeaseForm;
use App\Models\Apartment;
use App\Models\Lease;
use App\Support\Money;
use Flux\Flux;
use Illuminate\Support\Facades\Auth;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Livewire\Attributes\Title;
use Livewire\Component;
use Livewire\WithFileUploads;

new #[Title('Dodaj rachunek')] class extends Component {
    use WithFileUploads;

    public Apartment $apartment;

    public Lease $lease;

    public int $step = 1;

    /** @var \Livewire\Features\SupportFileUploads\TemporaryUploadedFile|null */
    public $invoice;

    public string $category = '';
    public string $supplier = '';
    public string $invoiceNumber = '';

    public string $totalAmount = '';
    public string $issuedOn = '';
    public string $periodFrom = '';
    public string $periodTo = '';
    public string $dueOn = '';
    public bool $partial = false;
    public string $tenantAmount = '';

    public function mount(Apartment $apartment, Lease $lease): void
    {
        $this->authorize('update', $apartment);

        $this->apartment = $apartment;
        $this->lease = $lease;
        $this->issuedOn = today()->toDateString();
        $this->dueOn = today()->addDays(14)->toDateString();
        $this->periodFrom = today()->subMonth()->startOfMonth()->max($lease->starts_on)->toDateString();
        $this->periodTo = today()->subMonth()->endOfMonth()->toDateString();
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    protected function stepRules(): array
    {
        return [
            1 => ['invoice' => ['required', 'file', 'mimes:pdf,jpg,jpeg,png,webp,heic', 'max:10240']],
            2 => [
                'category' => ['required', Rule::enum(BillCategory::class)],
                'supplier' => ['nullable', 'string', 'max:100'],
                'invoiceNumber' => ['nullable', 'string', 'max:100'],
            ],
            3 => [
                'totalAmount' => ['required', LeaseForm::moneyRule()],
                'issuedOn' => ['required', 'date'],
                'periodFrom' => ['required', 'date'],
                'periodTo' => ['required', 'date', 'after_or_equal:periodFrom'],
                'dueOn' => ['required', 'date'],
                'tenantAmount' => $this->partial ? ['required', LeaseForm::moneyRule()] : ['nullable'],
            ],
        ];
    }

    /**
     * @return array<string, string>
     */
    protected function validationAttributes(): array
    {
        return [
            'invoice' => __('plik faktury'),
            'category' => __('rodzaj rachunku'),
            'totalAmount' => __('kwota faktury'),
            'issuedOn' => __('data wystawienia'),
            'periodFrom' => __('początek okresu'),
            'periodTo' => __('koniec okresu'),
            'dueOn' => __('termin płatności'),
            'tenantAmount' => __('kwota dla najemcy'),
        ];
    }

    /**
     * @return array<string, string>
     */
    protected function messages(): array
    {
        return [
            'invoice.required' => __('Wgraj plik faktury (PDF albo zdjęcie).'),
            'invoice.mimes' => __('Faktura musi być plikiem PDF albo zdjęciem (JPG, PNG, WEBP, HEIC).'),
            'category.required' => __('Wybierz, za co jest ten rachunek.'),
            'periodTo.after_or_equal' => __('Koniec okresu nie może być przed jego początkiem.'),
        ];
    }

    protected function validateStep(int $step): void
    {
        $this->validate($this->stepRules()[$step]);

        if ($step === 3) {
            $total = Money::parse($this->totalAmount);
            if ($total <= 0) {
                throw ValidationException::withMessages(['totalAmount' => __('Kwota musi być większa od zera.')]);
            }
            if ($this->partial && Money::parse($this->tenantAmount) > $total) {
                throw ValidationException::withMessages(['tenantAmount' => __('Kwota dla najemcy nie może być większa niż kwota faktury.')]);
            }
        }
    }

    public function next(): void
    {
        $this->validateStep($this->step);

        if ($this->step === 3 && ! $this->partial) {
            $this->tenantAmount = $this->totalAmount;
        }

        $this->step = min($this->step + 1, 4);
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

    public function save(CreateBill $createBill): void
    {
        $this->authorize('update', $this->apartment);

        foreach ([1, 2, 3] as $step) {
            try {
                $this->validateStep($step);
            } catch (ValidationException $e) {
                $this->step = $step;
                throw $e;
            }
        }

        $createBill->handle($this->lease, Auth::user(), [
            'category' => BillCategory::from($this->category),
            'supplier' => filled($this->supplier) ? trim($this->supplier) : null,
            'invoice_number' => filled($this->invoiceNumber) ? trim($this->invoiceNumber) : null,
            'issued_on' => $this->issuedOn,
            'period_from' => $this->periodFrom,
            'period_to' => $this->periodTo,
            'total_amount' => Money::parse($this->totalAmount),
            'tenant_amount' => $this->chargedAmount(),
            'due_on' => $this->dueOn,
        ], $this->invoice);

        Flux::toast(variant: 'success', text: __('Rachunek dodany do rozliczeń.'));

        $this->redirectRoute('leases.ledger', [$this->apartment, $this->lease], navigate: true);
    }
}; ?>

@php
    $currency = $lease->currency;
    $fmt = fn (?int $amount) => \App\Support\Money::format((int) $amount, $currency);
@endphp

<div>
    @include('partials.apartment.header', ['current' => 'leases.ledger'])

    <div class="mx-auto w-full max-w-2xl">
        <flux:heading size="lg" class="mb-6">{{ __('Dodaj rachunek – :names', ['names' => $lease->tenantNames()]) }}</flux:heading>

        @include('partials.steps', ['steps' => [__('Faktura'), __('Rodzaj'), __('Kwota i okres'), __('Podsumowanie')], 'current' => $step])

        <flux:card class="bg-tray p-6 sm:p-8">
            @if ($step === 1)
                <form wire:submit="next" wire:key="step-1">
                    <flux:heading size="lg">{{ __('Wgraj fakturę') }}</flux:heading>
                    <flux:text class="mb-6 mt-1">{{ __('PDF albo zdjęcie faktury z telefonu. Plik zostanie zapisany przy rachunku.') }}</flux:text>

                    <label class="flex cursor-pointer flex-col items-center justify-center rounded-2xl border-2 border-dashed border-stone-300 bg-card px-6 py-10 text-center transition hover:border-accent dark:border-stone-700">
                        <flux:icon.arrow-up-tray class="mb-3 size-8 text-accent-content" />
                        @if ($invoice)
                            <span class="font-medium">{{ $invoice->getClientOriginalName() }}</span>
                            <flux:text class="text-sm">{{ __('Kliknij, aby wybrać inny plik') }}</flux:text>
                        @else
                            <span class="font-medium">{{ __('Kliknij, aby wybrać plik') }}</span>
                            <flux:text class="text-sm">{{ __('PDF, JPG, PNG – do 10 MB') }}</flux:text>
                        @endif
                        <input type="file" wire:model="invoice" accept=".pdf,image/*" class="sr-only">
                    </label>
                    <div wire:loading wire:target="invoice" class="mt-2 text-sm text-stone-500">{{ __('Wgrywanie…') }}</div>
                    <flux:error name="invoice" class="mt-2" />

                    <div class="mt-8 flex justify-between gap-3">
                        <flux:button :href="route('leases.ledger', [$apartment, $lease])" wire:navigate variant="ghost">{{ __('Anuluj') }}</flux:button>
                        <flux:button type="submit" variant="primary" icon:trailing="arrow-right" wire:loading.attr="disabled" wire:target="invoice">{{ __('Dalej') }}</flux:button>
                    </div>
                </form>
            @elseif ($step === 2)
                <form wire:submit="next" wire:key="step-2">
                    <flux:heading size="lg">{{ __('Za co jest ten rachunek?') }}</flux:heading>
                    <flux:text class="mb-6 mt-1">{{ __('Wybierz rodzaj i, jeśli chcesz, dopisz dostawcę.') }}</flux:text>

                    <div class="grid grid-cols-2 gap-3 sm:grid-cols-3">
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

                    <div class="mt-6 grid gap-4 sm:grid-cols-2">
                        <flux:input wire:model="supplier" :label="__('Dostawca')" :badge="__('Opcjonalnie')" :placeholder="__('np. Tauron, PGNiG')" />
                        <flux:input wire:model="invoiceNumber" :label="__('Numer faktury')" :badge="__('Opcjonalnie')" />
                    </div>

                    <div class="mt-8 flex justify-between gap-3">
                        <flux:button wire:click="back" variant="ghost" icon="arrow-left">{{ __('Wstecz') }}</flux:button>
                        <flux:button type="submit" variant="primary" icon:trailing="arrow-right">{{ __('Dalej') }}</flux:button>
                    </div>
                </form>
            @elseif ($step === 3)
                <form wire:submit="next" wire:key="step-3">
                    <flux:heading size="lg">{{ __('Kwota i okres') }}</flux:heading>
                    <flux:text class="mb-6 mt-1">{{ __('Przepisz dane z faktury.') }}</flux:text>

                    <div class="space-y-6">
                        @include('partials.money-input', ['model' => 'totalAmount', 'label' => __('Kwota do zapłaty z faktury'), 'currency' => $currency, 'autofocus' => true])

                        <div class="grid gap-4 sm:grid-cols-2">
                            <flux:input type="date" wire:model="periodFrom" :label="__('Za okres od')" />
                            <flux:input type="date" wire:model="periodTo" :label="__('do')" />
                            <flux:input type="date" wire:model="issuedOn" :label="__('Data wystawienia')" />
                            <flux:input type="date" wire:model="dueOn" :label="__('Termin płatności dla najemcy')" />
                        </div>

                        <div class="rounded-xl border border-line bg-card p-4">
                            <flux:checkbox wire:model.live="partial" :label="__('Najemca płaci tylko część tej faktury')" :description="__('Np. gdy faktura obejmuje też dni przed rozpoczęciem najmu.')" />
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
                <div wire:key="step-4">
                    <flux:heading size="lg">{{ __('Sprawdź, czy wszystko się zgadza') }}</flux:heading>

                    <div class="mt-6 divide-y divide-line rounded-xl border border-line bg-card">
                        <div class="flex items-start justify-between gap-4 p-4">
                            <div>
                                <flux:text class="text-sm">{{ __('Faktura') }}</flux:text>
                                <div class="mt-1 font-medium">{{ $invoice?->getClientOriginalName() }}</div>
                            </div>
                            <flux:button size="sm" variant="ghost" wire:click="goTo(1)">{{ __('Zmień') }}</flux:button>
                        </div>
                        <div class="flex items-start justify-between gap-4 p-4">
                            <div>
                                <flux:text class="text-sm">{{ __('Rodzaj') }}</flux:text>
                                <div class="mt-1 font-medium">
                                    {{ \App\Enums\BillCategory::from($category)->label() }}
                                    @if ($supplier) · {{ $supplier }} @endif
                                    @if ($invoiceNumber) · {{ __('nr :number', ['number' => $invoiceNumber]) }} @endif
                                </div>
                            </div>
                            <flux:button size="sm" variant="ghost" wire:click="goTo(2)">{{ __('Zmień') }}</flux:button>
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
                            <flux:button size="sm" variant="ghost" wire:click="goTo(3)">{{ __('Zmień') }}</flux:button>
                        </div>
                    </div>

                    <div class="mt-6 rounded-xl border border-accent/30 bg-badge p-4">
                        <flux:text>{{ __('Najemca zostanie obciążony kwotą') }}</flux:text>
                        <div class="text-2xl font-semibold text-accent-content">{{ $fmt($this->chargedAmount()) }}</div>
                    </div>

                    <div class="mt-8 flex justify-between gap-3">
                        <flux:button wire:click="back" variant="ghost" icon="arrow-left">{{ __('Wstecz') }}</flux:button>
                        <flux:button wire:click="save" variant="primary" icon="check">{{ __('Dodaj rachunek') }}</flux:button>
                    </div>
                </div>
            @endif
        </flux:card>
    </div>
</div>
