<?php

use App\Actions\Leases\AccrueRecurringCharges;
use App\Actions\Bills\DeleteBill;
use App\Actions\Leases\RecordPayment;
use App\Enums\PaymentMethod;
use App\Livewire\Forms\LeaseForm;
use App\Models\Apartment;
use App\Models\Bill;
use App\Models\Lease;
use App\Support\LeaseStatement;
use App\Support\Money;
use Carbon\CarbonImmutable;
use Flux\Flux;
use Illuminate\Support\Facades\Auth;
use Illuminate\Validation\Rule;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Locked;
use Livewire\Attributes\Title;
use Livewire\Component;

new #[Title('Rozliczenia')] class extends Component {
    public Apartment $apartment;

    #[Locked]
    public ?int $leaseId = null;

    public string $paymentAmount = '';
    public string $paidOn = '';
    public string $paymentMethod = 'transfer';
    public string $paymentNotes = '';

    public function mount(Apartment $apartment, ?Lease $lease = null): void
    {
        $this->apartment = $apartment;
        $lease ??= $apartment->mainLease();
        $this->leaseId = $lease?->id;

        // Make sure this month's fixed charges are there even if the daily job hasn't run yet.
        if ($lease) {
            app(AccrueRecurringCharges::class)->handle($lease);
        }
    }

    #[Computed]
    public function lease(): ?Lease
    {
        return $this->leaseId ? $this->apartment->leases()->with('tenants')->find($this->leaseId) : null;
    }

    #[Computed]
    public function statement(): ?LeaseStatement
    {
        return $this->lease ? new LeaseStatement($this->lease) : null;
    }

    #[Computed]
    public function leases()
    {
        return $this->apartment->leases()->with('tenants')->get();
    }

    public function openPayment(): void
    {
        $this->resetValidation();
        $balance = $this->statement->balance();
        $this->paymentAmount = $balance > 0 ? Money::toInput($balance) : '';
        $this->paidOn = today()->toDateString();
        $this->paymentMethod = 'transfer';
        $this->paymentNotes = '';

        Flux::modal('payment')->show();
    }

    public function savePayment(RecordPayment $recordPayment): void
    {
        $this->authorize('update', $this->apartment);

        $this->validate([
            'paymentAmount' => ['required', LeaseForm::moneyRule()],
            'paidOn' => ['required', 'date', 'before_or_equal:today'],
            'paymentMethod' => ['required', Rule::enum(PaymentMethod::class)],
            'paymentNotes' => ['nullable', 'string', 'max:1000'],
        ], ['paidOn.before_or_equal' => __('Data wpłaty nie może być z przyszłości.')], [
            'paymentAmount' => __('kwota'), 'paidOn' => __('data wpłaty'),
        ]);

        $amount = Money::parse($this->paymentAmount);
        if ($amount <= 0) {
            $this->addError('paymentAmount', __('Kwota musi być większa od zera.'));

            return;
        }

        $recordPayment->handle($this->lease, Auth::user(), $amount, CarbonImmutable::parse($this->paidOn), PaymentMethod::from($this->paymentMethod), filled($this->paymentNotes) ? trim($this->paymentNotes) : null);

        Flux::modal('payment')->close();
        Flux::toast(variant: 'success', text: __('Wpłata :amount zapisana.', ['amount' => Money::format($amount, $this->lease->currency)]));
        unset($this->statement);
    }

    public function deletePayment(int $entryId): void
    {
        $this->authorize('update', $this->apartment);

        $this->lease->ledgerEntries()->where('kind', 'payment')->findOrFail($entryId)->delete();

        Flux::toast(text: __('Wpłata usunięta.'));
        unset($this->statement);
    }

    public function deleteBill(int $billId, DeleteBill $deleteBill): void
    {
        $this->authorize('update', $this->apartment);

        $deleteBill->handle($this->lease->bills()->findOrFail($billId));

        Flux::toast(text: __('Rachunek usunięty.'));
        unset($this->statement);
    }
}; ?>

@php
    $lease = $this->lease;
    $statement = $this->statement;
    $fmt = fn (?int $amount) => \App\Support\Money::format((int) $amount, $lease?->currency ?? 'PLN');
@endphp

<div>
    @include('partials.apartment.header', ['current' => 'leases.ledger'])

    <div class="mx-auto w-full max-w-5xl">
        @if (! $lease)
            <div class="flex flex-col items-center rounded-2xl border-2 border-dashed border-stone-300 bg-tray px-6 py-16 text-center dark:border-stone-700">
                <div class="mb-4 flex size-16 items-center justify-center rounded-full bg-badge">
                    <flux:icon.banknotes class="size-8 text-accent-content" />
                </div>
                <flux:heading size="lg">{{ __('Nie ma jeszcze czego rozliczać') }}</flux:heading>
                <flux:text class="mt-2 max-w-md text-base">{{ __('Rozliczenia pojawią się, gdy dodasz najem.') }}</flux:text>
                <flux:button variant="primary" icon="plus" class="mt-6" :href="route('leases.create', $apartment)" wire:navigate>{{ __('Dodaj najem') }}</flux:button>
            </div>
        @else
            @php
                $balance = $statement->balance();
                $overdue = $statement->overdueAmount();
                $next = $statement->nextDueCharge();
            @endphp

            @if ($this->leases->count() > 1)
                <div class="mb-6 flex flex-wrap items-center gap-2">
                    <flux:text class="text-sm">{{ __('Najem:') }}</flux:text>
                    @foreach ($this->leases as $item)
                        <a href="{{ route('leases.ledger', [$apartment, $item]) }}" wire:navigate @class([
                            'rounded-full border px-3 py-1 text-sm transition',
                            'border-accent bg-accent text-accent-foreground' => $item->id === $lease->id,
                            'border-line bg-card hover:border-accent/40' => $item->id !== $lease->id,
                        ])>
                            {{ $item->tenantNames() }} · {{ $item->starts_on->format('m.Y') }}–{{ $item->effectiveEndsOn()?->format('m.Y') ?? '…' }}
                        </a>
                    @endforeach
                </div>
            @endif

            {{-- Balance --}}
            <div class="mb-8 grid gap-4 md:grid-cols-[1fr_auto]">
                <div @class([
                    'rounded-2xl border p-6',
                    'border-red-200 bg-red-50 dark:border-red-900/60 dark:bg-red-950/30' => $overdue > 0,
                    'border-line bg-card' => $overdue === 0,
                ])>
                    <flux:text>{{ $lease->tenantNames() }}</flux:text>
                    @if ($balance > 0)
                        <div class="mt-1 text-3xl font-semibold {{ $overdue > 0 ? 'text-red-700 dark:text-red-300' : '' }}">{{ __('Do zapłaty: :amount', ['amount' => $fmt($balance)]) }}</div>
                        @if ($overdue > 0)
                            <div class="mt-1 font-medium text-red-700 dark:text-red-300">{{ __('w tym po terminie: :amount', ['amount' => $fmt($overdue)]) }}</div>
                        @endif
                    @elseif ($balance < 0)
                        <div class="mt-1 text-3xl font-semibold text-accent-content">{{ __('Nadpłata: :amount', ['amount' => $fmt(-$balance)]) }}</div>
                        <flux:text class="mt-1">{{ __('Zostanie zaliczona na kolejne opłaty.') }}</flux:text>
                    @else
                        <div class="mt-1 flex items-center gap-2 text-3xl font-semibold text-accent-content"><flux:icon.check-circle class="size-8" /> {{ __('Wszystko rozliczone') }}</div>
                    @endif

                    @if ($next)
                        <flux:text class="mt-3">
                            {{ __('Najbliższa płatność: :amount do :date', ['amount' => $fmt($statement->remainingFor($next)), 'date' => $next->due_on?->translatedFormat('j F')]) }}
                        </flux:text>
                    @endif
                </div>

                <div class="flex flex-row gap-2 md:flex-col md:justify-center">
                    <flux:button variant="primary" icon="banknotes" wire:click="openPayment" class="flex-1">{{ __('Zapisz wpłatę') }}</flux:button>
                    <flux:button icon="document-plus" :href="route('bills.index')" wire:navigate class="flex-1">{{ __('Wgraj rachunki') }}</flux:button>
                    <flux:button icon="pencil-square" variant="ghost" size="sm" :href="route('bills.create', ['mieszkanie' => $apartment->id])" wire:navigate class="flex-1">{{ __('Dodaj rachunek ręcznie') }}</flux:button>
                </div>
            </div>

            {{-- Months --}}
            @php $months = $statement->months(); @endphp

            @if ($months->isEmpty())
                <flux:text class="py-10 text-center">{{ __('Nie ma jeszcze żadnych opłat ani wpłat. Stałe opłaty pojawią się 1. dnia miesiąca, a rachunki po ich dodaniu.') }}</flux:text>
            @else
                <div class="space-y-4">
                    @foreach ($months as $key => $month)
                        <details class="group overflow-hidden rounded-2xl border border-line bg-card" @if ($loop->index < 3 || $month['open'] > 0) open @endif wire:key="month-{{ $key }}">
                            <summary class="flex cursor-pointer list-none flex-wrap items-center gap-x-4 gap-y-1 p-4 hover:bg-tray">
                                <flux:icon.chevron-right variant="mini" class="text-stone-400 transition group-open:rotate-90" />
                                <span class="min-w-40 flex-1 font-semibold first-letter:uppercase">{{ \Illuminate\Support\Str::ucfirst($month['month']->isoFormat('MMMM YYYY')) }}</span>
                                <span class="text-sm text-stone-500">{{ __('naliczono :amount', ['amount' => $fmt($month['charged'])]) }}</span>
                                @if ($month['paid'] > 0)
                                    <span class="text-sm text-stone-500">· {{ __('wpłacono :amount', ['amount' => $fmt($month['paid'])]) }}</span>
                                @endif
                                @if ($month['charges']->isNotEmpty())
                                    @if ($month['open'] === 0)
                                        <flux:badge size="sm" color="green" icon="check">{{ __('Opłacone') }}</flux:badge>
                                    @elseif ($month['charges']->contains(fn ($c) => $statement->statusOf($c) === 'overdue'))
                                        <flux:badge size="sm" color="red">{{ __('Po terminie: :amount', ['amount' => $fmt($month['open'])]) }}</flux:badge>
                                    @else
                                        <flux:badge size="sm" color="amber">{{ __('Do zapłaty: :amount', ['amount' => $fmt($month['open'])]) }}</flux:badge>
                                    @endif
                                @endif
                            </summary>

                            <ul class="divide-y divide-line border-t border-line">
                                @foreach ($month['charges'] as $charge)
                                    @php
                                        $status = $statement->statusOf($charge);
                                        $bill = $charge->source instanceof \App\Models\Bill ? $charge->source : null;
                                    @endphp
                                    <li class="flex flex-wrap items-center gap-3 px-4 py-3" wire:key="entry-{{ $charge->id }}">
                                        <div class="flex size-8 shrink-0 items-center justify-center rounded-lg bg-badge">
                                            <flux:icon :name="$bill ? $bill->category->icon() : 'home-modern'" variant="micro" class="text-accent-content" />
                                        </div>
                                        <div class="min-w-0 flex-1">
                                            <div class="text-sm font-medium">{{ $charge->displayDescription() }}</div>
                                            <flux:text class="text-xs">
                                                @if ($charge->due_on) {{ __('termin: :date', ['date' => $charge->due_on->format('d.m.Y')]) }} @endif
                                                @if ($bill)
                                                    @if ($bill->hasFile())
                                                        · <flux:link :href="route('bills.file', $bill)" target="_blank" class="text-xs">{{ __('faktura') }}</flux:link>
                                                    @endif
                                                    · <flux:link :href="route('bills.edit', $bill)" wire:navigate class="text-xs">{{ __('edytuj') }}</flux:link>
                                                    @if ($bill->isPartial())
                                                        · {{ __('najemca płaci :part z :total', ['part' => $fmt($bill->tenant_amount), 'total' => $fmt($bill->total_amount)]) }}
                                                    @endif
                                                @endif
                                            </flux:text>
                                        </div>
                                        <div class="text-right">
                                            <div class="font-semibold">{{ $fmt($charge->amount) }}</div>
                                            @switch($status)
                                                @case('paid') <flux:text class="text-xs text-green-700! dark:text-green-400!">{{ __('opłacone') }}</flux:text> @break
                                                @case('overdue') <flux:text class="text-xs text-red-700! dark:text-red-400!">{{ __('po terminie, brakuje :amount', ['amount' => $fmt($statement->remainingFor($charge))]) }}</flux:text> @break
                                                @case('partial') <flux:text class="text-xs">{{ __('brakuje :amount', ['amount' => $fmt($statement->remainingFor($charge))]) }}</flux:text> @break
                                                @default <flux:text class="text-xs">{{ __('do zapłaty') }}</flux:text>
                                            @endswitch
                                        </div>
                                        @if ($bill)
                                            <flux:button size="xs" variant="ghost" icon="trash" wire:click="deleteBill({{ $bill->id }})" wire:confirm="{{ __('Usunąć ten rachunek? Najemca nie będzie już nim obciążony.') }}" :aria-label="__('Usuń rachunek')" />
                                        @endif
                                    </li>
                                @endforeach

                                @foreach ($month['payments'] as $payment)
                                    <li class="flex flex-wrap items-center gap-3 bg-green-50/60 px-4 py-3 dark:bg-green-950/20" wire:key="entry-{{ $payment->id }}">
                                        <div class="flex size-8 shrink-0 items-center justify-center rounded-lg bg-green-100 dark:bg-green-900/40">
                                            <flux:icon.arrow-down-left variant="micro" class="text-green-700 dark:text-green-400" />
                                        </div>
                                        <div class="min-w-0 flex-1">
                                            <div class="text-sm font-medium">{{ $payment->displayDescription() }}</div>
                                            <flux:text class="text-xs">
                                                {{ $payment->booked_on->format('d.m.Y') }}
                                                @if ($payment->notes) · {{ $payment->notes }} @endif
                                            </flux:text>
                                        </div>
                                        <div class="font-semibold text-green-700 dark:text-green-400">+ {{ $fmt($payment->amount) }}</div>
                                        <flux:button size="xs" variant="ghost" icon="trash" wire:click="deletePayment({{ $payment->id }})" wire:confirm="{{ __('Usunąć tę wpłatę?') }}" :aria-label="__('Usuń wpłatę')" />
                                    </li>
                                @endforeach
                            </ul>
                        </details>
                    @endforeach
                </div>
            @endif

            <flux:modal name="payment" class="w-full max-w-md">
                <form wire:submit="savePayment" class="space-y-6">
                    <div>
                        <flux:heading size="lg">{{ __('Zapisz wpłatę') }}</flux:heading>
                        <flux:text class="mt-2">{{ __('Wpłata najpierw pokryje najstarsze nieopłacone kwoty.') }}</flux:text>
                    </div>
                    @include('partials.money-input', ['model' => 'paymentAmount', 'label' => __('Kwota'), 'currency' => $lease->currency, 'autofocus' => true])
                    <flux:input type="date" wire:model="paidOn" :label="__('Data wpłaty')" class="max-w-56" />
                    <flux:radio.group wire:model="paymentMethod" :label="__('Sposób')" variant="cards" class="max-sm:flex-col">
                        @foreach (\App\Enums\PaymentMethod::cases() as $method)
                            <flux:radio :value="$method->value" :label="$method->label()" />
                        @endforeach
                    </flux:radio.group>
                    <flux:input wire:model="paymentNotes" :label="__('Notatka')" :badge="__('Opcjonalnie')" />
                    <div class="flex justify-end gap-2">
                        <flux:modal.close><flux:button variant="ghost">{{ __('Anuluj') }}</flux:button></flux:modal.close>
                        <flux:button type="submit" variant="primary">{{ __('Zapisz wpłatę') }}</flux:button>
                    </div>
                </form>
            </flux:modal>
        @endif
    </div>
</div>
