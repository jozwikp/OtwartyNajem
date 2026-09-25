<?php

use App\Actions\Leases\RecordPayment;
use App\Actions\Payments\BookBankTransactions;
use App\Actions\Payments\ImportBankStatement;
use App\Enums\BankTransactionStatus;
use App\Enums\PaymentMethod;
use App\Livewire\Forms\LeaseForm;
use App\Models\BankTransaction;
use App\Models\LedgerEntry;
use App\Support\LeaseStatement;
use App\Support\Money;
use App\Support\PaymentMatcher;
use Carbon\CarbonImmutable;
use Flux\Flux;
use Illuminate\Support\Facades\Auth;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Title;
use Livewire\Component;
use Livewire\WithFileUploads;

new #[Title('Wpłaty')] class extends Component {
    use WithFileUploads;

    // Manual payment
    public string $leaseId = '';
    public string $amount = '';
    public string $paidOn = '';
    public string $method = 'transfer';
    public string $notes = '';

    // Bank statement
    public $statement;

    /** @var array<int, bool> transaction id => ticked for booking */
    public array $selected = [];

    /** @var array<int, string> transaction id => lease id */
    public array $leaseFor = [];

    public function mount(): void
    {
        $this->paidOn = today()->toDateString();
        $this->syncChoices();
    }

    /**
     * Leases payments can be booked to, with what's due.
     *
     * @return \Illuminate\Support\Collection<int, array{lease: \App\Models\Lease, label: string, balance: int}>
     */
    #[Computed]
    public function leaseOptions()
    {
        return (new PaymentMatcher(Auth::user()))->leases()
            ->map(function ($lease) {
                $balance = (new LeaseStatement($lease))->balance();

                return [
                    'lease' => $lease,
                    'label' => $lease->tenantNames().' · '.$lease->apartment->label
                        .($balance > 0 ? ' — '.__('do zapłaty :amount', ['amount' => Money::format($balance, $lease->currency)]) : ''),
                    'balance' => $balance,
                ];
            })
            ->sortBy('label')
            ->keyBy(fn ($option) => $option['lease']->id);
    }

    #[Computed]
    public function pending()
    {
        return BankTransaction::where('user_id', Auth::id())
            ->whereIn('status', [BankTransactionStatus::Suggested, BankTransactionStatus::Review])
            ->with('lease.apartment', 'lease.tenants')
            ->orderBy('booked_on')
            ->get();
    }

    #[Computed]
    public function ignored()
    {
        return BankTransaction::where('user_id', Auth::id())
            ->where('status', BankTransactionStatus::Ignored)
            ->whereNull('decided_by')
            ->where('created_at', '>=', now()->subDays(30))
            ->latest('booked_on')
            ->get();
    }

    #[Computed]
    public function recentPayments()
    {
        return LedgerEntry::where('kind', 'payment')
            ->whereIn('lease_id', $this->leaseOptions->keys())
            ->with('lease.apartment', 'lease.tenants')
            ->latest('booked_on')->latest('id')
            ->limit(15)
            ->get();
    }

    protected function syncChoices(): void
    {
        foreach ($this->pending as $transaction) {
            $this->selected[$transaction->id] ??= $transaction->status === BankTransactionStatus::Suggested;
            $this->leaseFor[$transaction->id] ??= (string) $transaction->lease_id;
        }
    }

    // --- Manual ---------------------------------------------------------------

    public function updatedLeaseId(): void
    {
        $balance = $this->leaseOptions->get((int) $this->leaseId)['balance'] ?? 0;
        $this->amount = $balance > 0 ? Money::toInput($balance) : '';
    }

    public function saveManual(RecordPayment $recordPayment): void
    {
        $this->validate([
            'leaseId' => ['required', Rule::in($this->leaseOptions->keys()->map(fn ($id) => (string) $id))],
            'amount' => ['required', LeaseForm::moneyRule()],
            'paidOn' => ['required', 'date', 'before_or_equal:today'],
            'method' => ['required', Rule::enum(PaymentMethod::class)],
            'notes' => ['nullable', 'string', 'max:1000'],
        ], [
            'leaseId.required' => __('Wybierz, kto zapłacił.'),
            'paidOn.before_or_equal' => __('Data wpłaty nie może być z przyszłości.'),
        ], ['amount' => __('kwota'), 'paidOn' => __('data wpłaty')]);

        $amount = Money::parse($this->amount);
        if ($amount <= 0) {
            $this->addError('amount', __('Kwota musi być większa od zera.'));

            return;
        }

        $lease = $this->leaseOptions->get((int) $this->leaseId)['lease'];
        $this->authorize('update', $lease->apartment);

        $recordPayment->handle($lease, Auth::user(), $amount, CarbonImmutable::parse($this->paidOn), PaymentMethod::from($this->method), filled($this->notes) ? trim($this->notes) : null);

        Flux::toast(variant: 'success', text: __('Zapisano wpłatę :amount od: :names.', ['amount' => Money::format($amount, $lease->currency), 'names' => $lease->tenantNames()]));

        $this->reset('leaseId', 'amount', 'notes');
        unset($this->leaseOptions, $this->recentPayments);
    }

    // --- Bank statement ---------------------------------------------------------

    public function updatedStatement(ImportBankStatement $import): void
    {
        $this->validate(['statement' => ['required', 'file', 'mimes:csv,txt', 'max:5120']], [
            'statement.mimes' => __('Wgraj plik CSV z banku.'),
        ]);

        try {
            $result = $import->handle(Auth::user(), $this->statement->get(), $this->statement->getClientOriginalName());
        } catch (\RuntimeException $e) {
            $this->addError('statement', $e->getMessage());

            return;
        } finally {
            $this->reset('statement');
        }

        $new = $result->transactions()->count();
        $toCheck = $result->transactions()->whereIn('status', ['suggested', 'review'])->count();

        Flux::toast(variant: 'success', text: __('Przeczytano :rows operacji, w tym :incoming wpływów. Do przejrzenia: :check.', [
            'rows' => $result->rows_total, 'incoming' => $result->incoming_total, 'check' => $toCheck,
        ]).($result->duplicates ? ' '.__('Pominięto :count wgranych już wcześniej.', ['count' => $result->duplicates]) : ''));

        unset($this->pending, $this->ignored);
        $this->syncChoices();
    }

    public function updatedLeaseFor($value, $key): void
    {
        // Choosing a tenant for a doubtful transfer ticks it.
        $this->selected[(int) $key] = $value !== '';
    }

    public function assign(int $transactionId): void
    {
        $transaction = BankTransaction::where('user_id', Auth::id())->findOrFail($transactionId);
        $transaction->forceFill(['status' => BankTransactionStatus::Review, 'match_reason' => __('Dodane ręcznie do sprawdzenia.')])->save();

        unset($this->pending, $this->ignored);
        $this->syncChoices();
    }

    public function skip(int $transactionId): void
    {
        BankTransaction::where('user_id', Auth::id())->whereKey($transactionId)->update([
            'status' => BankTransactionStatus::Ignored,
            'decided_by' => Auth::id(),
            'decided_at' => now(),
        ]);

        unset($this->selected[$transactionId], $this->leaseFor[$transactionId], $this->pending);
    }

    /**
     * @return array<int, int>
     */
    protected function choices(): array
    {
        $choices = [];
        foreach ($this->pending as $transaction) {
            if (($this->selected[$transaction->id] ?? false) && filled($this->leaseFor[$transaction->id] ?? '')) {
                $choices[$transaction->id] = (int) $this->leaseFor[$transaction->id];
            }
        }

        return $choices;
    }

    public function chosenTotal(): int
    {
        return (int) $this->pending->whereIn('id', array_keys($this->choices()))->sum('amount');
    }

    public function book(BookBankTransactions $book): void
    {
        $choices = $this->choices();

        if ($choices === []) {
            Flux::toast(variant: 'warning', text: __('Zaznacz przelewy i wybierz najemców.'));

            return;
        }

        try {
            $count = $book->handle(Auth::user(), $choices);
        } catch (ValidationException $e) {
            Flux::toast(variant: 'danger', text: $e->validator->errors()->first());

            return;
        }

        Flux::toast(variant: 'success', text: trans_choice('Zaksięgowano :count wpłatę.|Zaksięgowano :count wpłaty.|Zaksięgowano :count wpłat.', $count));

        $this->selected = array_diff_key($this->selected, $choices);
        $this->leaseFor = array_diff_key($this->leaseFor, $choices);
        unset($this->pending, $this->leaseOptions, $this->recentPayments);
    }
}; ?>

@php
    $pln = fn (int $amount, string $currency = 'PLN') => \App\Support\Money::format($amount, $currency);
    $suggested = $this->pending->where('status', \App\Enums\BankTransactionStatus::Suggested);
    $review = $this->pending->where('status', \App\Enums\BankTransactionStatus::Review);
    $choiceCount = count(array_filter($selected, fn ($v, $id) => $v && filled($leaseFor[$id] ?? ''), ARRAY_FILTER_USE_BOTH));
@endphp

<div class="mx-auto w-full max-w-5xl">
    <div class="mb-8">
        <flux:heading size="xl" level="1">{{ __('Wpłaty') }}</flux:heading>
        <flux:text class="mt-1 text-base">{{ __('Zapisz, kto ile zapłacił – ręcznie albo z wyciągu bankowego.') }}</flux:text>
    </div>

    <div class="grid gap-6 lg:grid-cols-2">
        {{-- Manual --}}
        <flux:card class="bg-tray">
            <flux:heading size="lg">{{ __('Wpisz wpłatę ręcznie') }}</flux:heading>
            <flux:text class="mb-5 mt-1 text-sm">{{ __('Np. gdy najemca zapłacił gotówką albo wolisz wpisać przelew sam.') }}</flux:text>

            @if ($this->leaseOptions->isEmpty())
                <flux:text>{{ __('Najpierw dodaj najem w którymś z mieszkań.') }}</flux:text>
            @else
                <form wire:submit="saveManual" class="space-y-5">
                    <flux:select wire:model.live="leaseId" :label="__('Kto zapłacił?')">
                        <flux:select.option value="">{{ __('— wybierz najemcę —') }}</flux:select.option>
                        @foreach ($this->leaseOptions as $id => $option)
                            <flux:select.option :value="$id">{{ $option['label'] }}</flux:select.option>
                        @endforeach
                    </flux:select>

                    <div class="grid gap-4 sm:grid-cols-2">
                        @include('partials.money-input', ['model' => 'amount', 'label' => __('Kwota'), 'currency' => $this->leaseOptions->get((int) $leaseId)['lease']->currency ?? 'PLN'])
                        <flux:input type="date" wire:model="paidOn" :label="__('Data wpłaty')" />
                    </div>

                    <flux:radio.group wire:model="method" variant="segmented" :label="__('Sposób')">
                        @foreach (\App\Enums\PaymentMethod::cases() as $option)
                            <flux:radio :value="$option->value" :label="$option->label()" />
                        @endforeach
                    </flux:radio.group>

                    <flux:input wire:model="notes" :label="__('Notatka')" :badge="__('Opcjonalnie')" />

                    <flux:button type="submit" variant="primary" icon="check" class="w-full">{{ __('Zapisz wpłatę') }}</flux:button>
                </form>
            @endif
        </flux:card>

        {{-- Bank statement --}}
        <flux:card class="bg-tray">
            <flux:heading size="lg">{{ __('Wgraj wyciąg z banku') }}</flux:heading>
            <flux:text class="mb-5 mt-1 text-sm">{{ __('Plik CSV z bankowości internetowej (np. mBank: Historia → Eksportuj → CSV). Weźmiemy tylko wpływy i sami dopasujemy je do najemców – Ty tylko potwierdzasz.') }}</flux:text>

            <label class="relative flex cursor-pointer flex-col items-center justify-center rounded-2xl border-2 border-dashed border-stone-300 bg-card px-6 py-10 text-center transition hover:border-accent dark:border-stone-700">
                <div class="mb-3 flex size-12 items-center justify-center rounded-full bg-badge">
                    <flux:icon.document-arrow-up class="size-6 text-accent-content" />
                </div>
                <span class="font-semibold">{{ __('Wybierz plik CSV') }}</span>
                <flux:text class="mt-1 text-sm">{{ __('Twoje wydatki z wyciągu nie są zapisywane – tylko wpływy.') }}</flux:text>
                <input type="file" wire:model="statement" accept=".csv,text/csv,text/plain" class="absolute inset-0 cursor-pointer opacity-0">
                <div wire:loading.flex wire:target="statement" class="absolute inset-0 items-center justify-center gap-2 rounded-2xl bg-card/90 font-medium">
                    <flux:icon.arrow-path class="animate-spin" /> {{ __('Czytamy wyciąg…') }}
                </div>
            </label>
            <flux:error name="statement" class="mt-2" />
        </flux:card>
    </div>

    {{-- Review --}}
    @if ($this->pending->isNotEmpty())
        <section class="mt-10 space-y-8">
            @if ($suggested->isNotEmpty())
                <div>
                    <flux:heading size="lg">{{ __('Rozpoznane wpłaty od najemców') }} ({{ $suggested->count() }})</flux:heading>
                    <flux:text class="mb-3 mt-1 text-sm">{{ __('Zaznaczone do zaksięgowania. Odznacz, jeśli coś się nie zgadza.') }}</flux:text>
                    @include('partials.payments.transaction-list', ['transactions' => $suggested])
                </div>
            @endif

            @if ($review->isNotEmpty())
                <div>
                    <flux:heading size="lg">{{ __('Do Twojej decyzji') }} ({{ $review->count() }})</flux:heading>
                    <flux:text class="mb-3 mt-1 text-sm">{{ __('Nie mamy pewności, od kogo są te przelewy. Wybierz najemcę albo kliknij „To nie od najemcy”.') }}</flux:text>
                    @include('partials.payments.transaction-list', ['transactions' => $review])
                </div>
            @endif

            <div class="sticky bottom-4 z-10 flex flex-wrap items-center justify-between gap-3 rounded-2xl border border-line bg-card p-4 shadow-lg">
                <flux:text>
                    {{ trans_choice('Zaznaczono :count przelew|Zaznaczono :count przelewy|Zaznaczono :count przelewów', $choiceCount) }}
                    @if ($choiceCount) · {{ __('razem :amount', ['amount' => $pln($this->chosenTotal())]) }} @endif
                </flux:text>
                <flux:button variant="primary" icon="check" wire:click="book" :disabled="$choiceCount === 0">{{ __('Zaksięguj zaznaczone') }}</flux:button>
            </div>
        </section>
    @endif

    @if ($this->ignored->isNotEmpty())
        <details class="mt-8 rounded-2xl border border-line bg-card">
            <summary class="cursor-pointer list-none p-4 font-medium hover:bg-tray">
                {{ __('Pominięte wpływy – nie od najemców (:count)', ['count' => $this->ignored->count()]) }}
                <span class="text-sm font-normal text-stone-500">· {{ __('zwroty, przelewy własne itp. Kliknij, jeśli czegoś brakuje.') }}</span>
            </summary>
            <ul class="divide-y divide-line border-t border-line">
                @foreach ($this->ignored as $transaction)
                    <li class="flex flex-wrap items-center gap-3 px-4 py-2 text-sm" wire:key="ignored-{{ $transaction->id }}">
                        <span class="w-24 text-stone-500">{{ $transaction->booked_on->format('d.m.Y') }}</span>
                        <span class="min-w-0 flex-1 truncate">{{ $transaction->sender_name }}@if ($transaction->title) · {{ $transaction->title }}@endif</span>
                        <span class="font-medium">{{ $pln($transaction->amount, $transaction->currency) }}</span>
                        <flux:button size="xs" wire:click="assign({{ $transaction->id }})">{{ __('To od najemcy') }}</flux:button>
                    </li>
                @endforeach
            </ul>
        </details>
    @endif

    {{-- Recent --}}
    <section class="mt-10">
        <flux:heading size="lg" class="mb-3">{{ __('Ostatnie wpłaty') }}</flux:heading>
        @if ($this->recentPayments->isEmpty())
            <flux:text>{{ __('Nie ma jeszcze żadnych wpłat.') }}</flux:text>
        @else
            <ul class="divide-y divide-line rounded-2xl border border-line bg-card">
                @foreach ($this->recentPayments as $payment)
                    <li wire:key="payment-{{ $payment->id }}">
                        <a href="{{ route('leases.ledger', [$payment->lease->apartment_id, $payment->lease]) }}" wire:navigate class="flex flex-wrap items-center gap-3 px-4 py-3 hover:bg-tray">
                            <span class="flex size-8 shrink-0 items-center justify-center rounded-lg bg-green-100 dark:bg-green-900/40">
                                <flux:icon.arrow-down-left variant="micro" class="text-green-700 dark:text-green-400" />
                            </span>
                            <div class="min-w-0 flex-1">
                                <div class="text-sm font-medium">{{ $payment->lease->tenantNames() }} · {{ $payment->lease->apartment->label }}</div>
                                <flux:text class="text-xs">
                                    {{ $payment->booked_on->format('d.m.Y') }} · {{ $payment->source_type ? __('z wyciągu') : ($payment->payment_method?->label() ?? '') }}
                                    @if ($payment->notes) · {{ \Illuminate\Support\Str::limit($payment->notes, 60) }} @endif
                                </flux:text>
                            </div>
                            <span class="font-semibold text-green-700 dark:text-green-400">+ {{ $pln($payment->amount, $payment->currency) }}</span>
                        </a>
                    </li>
                @endforeach
            </ul>
        @endif
    </section>
</div>
