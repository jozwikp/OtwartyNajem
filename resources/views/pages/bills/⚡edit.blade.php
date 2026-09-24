<?php

use App\Actions\Bills\DeleteBill;
use App\Actions\Bills\UpdateBill;
use App\Enums\BillCategory;
use App\Enums\BillStatus;
use App\Livewire\Forms\LeaseForm;
use App\Models\Bill;
use App\Support\Currencies;
use App\Support\Money;
use Flux\Flux;
use Illuminate\Support\Facades\Auth;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Title;
use Livewire\Component;

new #[Title('Rachunek')] class extends Component {
    public Bill $bill;

    public string $apartmentId = '';
    public string $category = '';
    public string $supplier = '';
    public string $invoiceNumber = '';
    public string $issuedOn = '';
    public string $periodFrom = '';
    public string $periodTo = '';
    public string $dueOn = '';
    public string $currency = 'PLN';
    public string $totalAmount = '';
    public string $tenantAmount = '';

    public function mount(Bill $bill): void
    {
        $this->bill = $bill;
        $this->apartmentId = (string) $bill->apartment_id;
        $this->category = (string) $bill->category?->value;
        $this->supplier = (string) $bill->supplier;
        $this->invoiceNumber = (string) $bill->invoice_number;
        $this->issuedOn = (string) $bill->issued_on?->toDateString();
        $this->periodFrom = (string) $bill->period_from?->toDateString();
        $this->periodTo = (string) $bill->period_to?->toDateString();
        $this->dueOn = (string) $bill->due_on?->toDateString();
        $this->currency = $bill->currency ?? Currencies::DEFAULT;
        $this->totalAmount = Money::toInput($bill->total_amount);
        $this->tenantAmount = Money::toInput($bill->tenant_amount);
    }

    #[Computed]
    public function apartments()
    {
        return Auth::user()->apartments()->orderBy('label')->get();
    }

    public function save(UpdateBill $updateBill, bool $approve = false): void
    {
        $this->authorize('update', $this->bill);

        $this->validate([
            'apartmentId' => ['required', Rule::in($this->apartments->pluck('id')->map(fn ($id) => (string) $id))],
            'category' => ['required', Rule::enum(BillCategory::class)],
            'supplier' => ['nullable', 'string', 'max:100'],
            'invoiceNumber' => ['nullable', 'string', 'max:100'],
            'issuedOn' => ['required', 'date'],
            'periodFrom' => ['required', 'date'],
            'periodTo' => ['required', 'date', 'after_or_equal:periodFrom'],
            'dueOn' => ['required', 'date'],
            'currency' => ['required', Rule::in(Currencies::codes())],
            'totalAmount' => ['required', LeaseForm::moneyRule()],
            'tenantAmount' => ['required', LeaseForm::moneyRule()],
        ], [
            'apartmentId.required' => __('Wybierz mieszkanie.'),
            'periodTo.after_or_equal' => __('Koniec okresu nie może być przed jego początkiem.'),
        ], [
            'category' => __('rodzaj'), 'issuedOn' => __('data wystawienia'), 'periodFrom' => __('początek okresu'),
            'periodTo' => __('koniec okresu'), 'dueOn' => __('termin płatności'),
            'totalAmount' => __('kwota faktury'), 'tenantAmount' => __('kwota dla najemcy'),
        ]);

        $total = Money::parse($this->totalAmount);
        $tenant = Money::parse($this->tenantAmount);
        if ($tenant > $total) {
            $this->addError('tenantAmount', __('Kwota dla najemcy nie może być większa niż kwota faktury.'));

            return;
        }

        try {
            $updateBill->handle($this->bill, Auth::user(), [
                'apartment_id' => (int) $this->apartmentId,
                'category' => BillCategory::from($this->category),
                'supplier' => filled($this->supplier) ? trim($this->supplier) : null,
                'invoice_number' => filled($this->invoiceNumber) ? trim($this->invoiceNumber) : null,
                'issued_on' => $this->issuedOn,
                'period_from' => $this->periodFrom,
                'period_to' => $this->periodTo,
                'due_on' => $this->dueOn,
                'currency' => $this->currency,
                'total_amount' => $total,
                'tenant_amount' => $tenant,
            ], $approve);
        } catch (ValidationException $e) {
            $this->addError('bill', $e->validator->errors()->first());

            return;
        }

        Flux::toast(variant: 'success', text: $approve ? __('Rachunek zatwierdzony.') : __('Zmiany zapisane.'));

        $this->redirectRoute('bills.index', navigate: true);
    }

    public function approve(UpdateBill $updateBill): void
    {
        $this->save($updateBill, approve: true);
    }

    public function delete(DeleteBill $deleteBill): void
    {
        $this->authorize('delete', $this->bill);
        $deleteBill->handle($this->bill);

        Flux::toast(text: __('Rachunek usunięty.'));
        $this->redirectRoute('bills.index', navigate: true);
    }
}; ?>

@php $approved = $bill->status === \App\Enums\BillStatus::Approved; @endphp

<div class="mx-auto w-full max-w-6xl">
    <flux:breadcrumbs class="mb-4">
        <flux:breadcrumbs.item :href="route('bills.index')" wire:navigate>{{ __('Rachunki') }}</flux:breadcrumbs.item>
        <flux:breadcrumbs.item>{{ $bill->file_name }}</flux:breadcrumbs.item>
    </flux:breadcrumbs>

    <div class="mb-6 flex flex-wrap items-center gap-3">
        <flux:heading size="xl" level="1">{{ $bill->category?->label() ?? __('Rachunek') }}@if ($bill->supplier) · {{ $bill->supplier }}@endif</flux:heading>
        <flux:badge :color="$approved ? 'green' : 'amber'">{{ $bill->status->label() }}</flux:badge>
    </div>

    <div class="grid gap-6 lg:grid-cols-2">
        <form wire:submit="save" class="space-y-6">
            @if ($bill->ai_result)
                <div class="rounded-xl border border-line bg-badge/60 p-4 text-sm">
                    <div class="flex items-center gap-2 font-medium"><flux:icon.sparkles variant="mini" class="text-accent-content" /> {{ __('Odczytane automatycznie') }}</div>
                    @if ($bill->aiValue('address_on_invoice'))
                        <div class="mt-1">{{ __('Adres na fakturze: :address', ['address' => $bill->aiValue('address_on_invoice')]) }}</div>
                    @endif
                    @if ($bill->aiValue('match_reason'))
                        <div class="mt-1 text-stone-600 dark:text-stone-300">{{ $bill->aiValue('match_reason') }}</div>
                    @endif
                    @if ($bill->aiValue('notes'))
                        <div class="mt-1 italic text-stone-600 dark:text-stone-300">{{ $bill->aiValue('notes') }}</div>
                    @endif
                </div>
            @endif

            <flux:card class="space-y-6 bg-tray">
                <flux:select wire:model="apartmentId" :label="__('Mieszkanie')">
                    <flux:select.option value="">{{ __('— wybierz —') }}</flux:select.option>
                    @foreach ($this->apartments as $apartment)
                        <flux:select.option :value="$apartment->id">{{ $apartment->label }}</flux:select.option>
                    @endforeach
                </flux:select>

                <flux:select wire:model="category" :label="__('Rodzaj rachunku')">
                    <flux:select.option value="">{{ __('— wybierz —') }}</flux:select.option>
                    @foreach (\App\Enums\BillCategory::cases() as $option)
                        <flux:select.option :value="$option->value">{{ $option->label() }}</flux:select.option>
                    @endforeach
                </flux:select>

                <div class="grid gap-4 sm:grid-cols-2">
                    <flux:input wire:model="supplier" :label="__('Dostawca')" />
                    <flux:input wire:model="invoiceNumber" :label="__('Numer faktury')" />
                    <flux:input type="date" wire:model="periodFrom" :label="__('Okres od')" />
                    <flux:input type="date" wire:model="periodTo" :label="__('Okres do')" />
                    <flux:input type="date" wire:model="issuedOn" :label="__('Data wystawienia')" />
                    <flux:input type="date" wire:model="dueOn" :label="__('Termin płatności')" />
                </div>

                <div class="grid gap-4 sm:grid-cols-2">
                    @include('partials.money-input', ['model' => 'totalAmount', 'label' => __('Kwota z faktury'), 'currency' => $currency])
                    @include('partials.money-input', ['model' => 'tenantAmount', 'label' => __('Kwota dla najemcy'), 'currency' => $currency])
                </div>

                <flux:select wire:model.live="currency" :label="__('Waluta')" class="max-w-64">
                    @foreach (\App\Support\Currencies::all() as $code => $name)
                        <flux:select.option :value="$code">{{ $name }}</flux:select.option>
                    @endforeach
                </flux:select>
            </flux:card>

            @error('bill')
                <flux:callout icon="exclamation-triangle" color="amber"><flux:callout.text>{{ $message }}</flux:callout.text></flux:callout>
            @enderror

            @if ($approved)
                <flux:text class="text-sm">
                    {{ $bill->lease
                        ? __('Zmiany od razu zaktualizują rozliczenia najemcy: :names.', ['names' => $bill->lease->tenantNames()])
                        : __('Ten rachunek nie obciąża najemcy (brak najmu w okresie rachunku).') }}
                </flux:text>
            @endif

            <div class="flex flex-wrap items-center justify-between gap-3">
                <flux:button variant="ghost" icon="trash" wire:click="delete" wire:confirm="{{ __('Usunąć ten rachunek? Najemca nie będzie już nim obciążony.') }}">{{ __('Usuń') }}</flux:button>
                <div class="flex flex-wrap gap-2">
                    <flux:button :href="route('bills.index')" wire:navigate variant="ghost">{{ __('Anuluj') }}</flux:button>
                    @if ($approved)
                        <flux:button type="submit" variant="primary" icon="check">{{ __('Zapisz zmiany') }}</flux:button>
                    @else
                        <flux:button type="submit">{{ __('Zapisz') }}</flux:button>
                        <flux:button variant="primary" icon="check" wire:click="approve">{{ __('Zapisz i zatwierdź') }}</flux:button>
                    @endif
                </div>
            </div>
        </form>

        <div class="lg:sticky lg:top-6 lg:self-start">
            <div class="overflow-hidden rounded-2xl border border-line bg-card">
                @if ($bill->isImage())
                    <img src="{{ route('bills.file', $bill) }}" alt="{{ $bill->file_name }}" class="w-full">
                @else
                    <iframe src="{{ route('bills.file', $bill) }}" title="{{ $bill->file_name }}" class="h-[75vh] w-full"></iframe>
                @endif
            </div>
            <flux:link :href="route('bills.file', $bill)" target="_blank" class="mt-2 inline-block text-sm">{{ __('Otwórz fakturę w nowej karcie') }}</flux:link>
        </div>
    </div>
</div>
