<?php

use App\Actions\Leases\AddRecurringCharge;
use App\Actions\Leases\ChangeChargeAmount;
use App\Actions\Leases\EndLease;
use App\Actions\Leases\UpdateLease;
use App\Enums\ChargeType;
use App\Enums\LeaseStatus;
use App\Livewire\Forms\LeaseForm;
use App\Models\Apartment;
use App\Models\Lease;
use App\Models\LeaseTenant;
use App\Models\RecurringCharge;
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

new #[Title('Najem')] class extends Component {
    public Apartment $apartment;

    #[Locked]
    public ?int $leaseId = null;

    // Tenant modal
    public ?int $editingTenantId = null;
    public string $tenantFirstName = '';
    public string $tenantLastName = '';
    public string $tenantEmail = '';
    public string $tenantPhone = '';

    // Charge modals
    public ?int $chargeId = null;
    public string $chargeType = 'other';
    public string $chargeName = '';
    public string $amount = '';
    public string $validFrom = '';

    // End lease / deposit return
    public string $endsOn = '';
    public string $returnedOn = '';
    public string $returnedAmount = '';
    public string $returnNotes = '';

    public function mount(Apartment $apartment, ?Lease $lease = null): void
    {
        $this->apartment = $apartment;
        $this->leaseId = ($lease ?? $apartment->mainLease())?->id;
    }

    #[Computed]
    public function lease(): ?Lease
    {
        return $this->leaseId
            ? $this->apartment->leases()->with(['tenants', 'recurringCharges.rates'])->find($this->leaseId)
            : null;
    }

    #[Computed]
    public function otherLeases()
    {
        return $this->apartment->leases()->with('tenants')->whereKeyNot($this->leaseId ?? 0)->get();
    }

    #[Computed]
    public function statement(): ?LeaseStatement
    {
        return $this->lease ? new LeaseStatement($this->lease) : null;
    }

    protected function money(int $amount): string
    {
        return Money::format($amount, $this->lease->currency);
    }

    /**
     * Months to choose from when a new amount starts (the next 12 months that can still be changed).
     *
     * @return array<string, string>
     */
    public function monthOptions(CarbonImmutable $from): array
    {
        $options = [];
        for ($i = 0; $i < 12; $i++) {
            $month = $from->addMonths($i);
            $options[$month->toDateString()] = $month->isoFormat('MMMM YYYY');
        }

        return $options;
    }

    // --- Notifications -------------------------------------------------------

    #[Computed]
    public function digest(): ?\App\Support\TenantDigest
    {
        return $this->lease ? new \App\Support\TenantDigest($this->lease) : null;
    }

    public function toggleNotifications(UpdateLease $updateLease): void
    {
        $this->authorize('update', $this->apartment);

        $updateLease->handle($this->lease, ['notify_tenants' => ! $this->lease->notify_tenants]);

        Flux::toast(text: $this->lease->notify_tenants
            ? __('Powiadomienia włączone. Najemca dostanie wiadomość, gdy pojawią się nowe opłaty.')
            : __('Powiadomienia wyłączone.'));
        unset($this->lease, $this->digest);
    }

    // --- Tenants -------------------------------------------------------------

    public function openTenant(?int $tenantId = null): void
    {
        $this->resetValidation();
        $tenant = $tenantId ? $this->lease->tenants->firstWhere('id', $tenantId) : null;

        $this->editingTenantId = $tenant?->id;
        $this->tenantFirstName = $tenant->first_name ?? '';
        $this->tenantLastName = $tenant->last_name ?? '';
        $this->tenantEmail = $tenant->email ?? '';
        $this->tenantPhone = $tenant->phone ?? '';

        Flux::modal('tenant')->show();
    }

    public function saveTenant(): void
    {
        $this->authorize('update', $this->apartment);

        $data = $this->validate([
            'tenantFirstName' => ['required', 'string', 'max:100'],
            'tenantLastName' => ['required', 'string', 'max:100'],
            'tenantEmail' => ['nullable', 'email', 'max:255'],
            'tenantPhone' => ['nullable', 'string', 'max:40'],
        ], attributes: [
            'tenantFirstName' => __('imię'), 'tenantLastName' => __('nazwisko'),
            'tenantEmail' => __('e-mail'), 'tenantPhone' => __('telefon'),
        ]);

        $attributes = [
            'first_name' => trim($data['tenantFirstName']),
            'last_name' => trim($data['tenantLastName']),
            'email' => filled($data['tenantEmail']) ? mb_strtolower(trim($data['tenantEmail'])) : null,
            'phone' => filled($data['tenantPhone']) ? trim($data['tenantPhone']) : null,
        ];

        if ($this->editingTenantId) {
            $this->lease->tenants()->findOrFail($this->editingTenantId)->update($attributes);
        } else {
            $this->lease->tenants()->create([...$attributes, 'is_primary' => $this->lease->tenants->isEmpty()]);
        }

        Flux::modal('tenant')->close();
        Flux::toast(variant: 'success', text: __('Dane najemcy zapisane.'));
        unset($this->lease);
    }

    public function makePrimary(int $tenantId): void
    {
        $this->authorize('update', $this->apartment);

        foreach ($this->lease->tenants as $tenant) {
            $tenant->update(['is_primary' => $tenant->id === $tenantId]);
        }
        unset($this->lease);
    }

    public function removeTenant(int $tenantId): void
    {
        $this->authorize('update', $this->apartment);

        if ($this->lease->tenants->count() <= 1) {
            Flux::toast(variant: 'danger', text: __('Najem musi mieć co najmniej jednego najemcę.'));

            return;
        }

        $tenant = $this->lease->tenants()->findOrFail($tenantId);
        $tenant->delete();

        if ($tenant->is_primary) {
            $this->lease->tenants()->first()?->update(['is_primary' => true]);
        }

        Flux::toast(text: __(':name został(a) usunięty(a) z najmu.', ['name' => $tenant->full_name]));
        unset($this->lease);
    }

    // --- Fixed charges ---------------------------------------------------------

    public function openChangeAmount(int $chargeId): void
    {
        $this->resetValidation();
        $charge = $this->lease->recurringCharges->firstWhere('id', $chargeId);

        $this->chargeId = $charge->id;
        $this->amount = '';
        $this->validFrom = ChangeChargeAmount::earliestMonth($charge)->toDateString();

        Flux::modal('change-amount')->show();
    }

    public function changeAmount(ChangeChargeAmount $changeChargeAmount): void
    {
        $this->authorize('update', $this->apartment);

        $this->validate([
            'amount' => ['required', LeaseForm::moneyRule()],
            'validFrom' => ['required', 'date'],
        ], attributes: ['amount' => __('nowa kwota'), 'validFrom' => __('od kiedy')]);

        $charge = $this->lease->recurringCharges()->findOrFail($this->chargeId);
        $changeChargeAmount->handle($charge, Auth::user(), Money::parse($this->amount), CarbonImmutable::parse($this->validFrom));

        Flux::modal('change-amount')->close();
        Flux::toast(variant: 'success', text: __('Nowa kwota zapisana.'));
        unset($this->lease, $this->statement);
    }

    public function openAddCharge(): void
    {
        $this->resetValidation();
        $this->chargeType = $this->lease->recurringCharges->contains('type', ChargeType::Rent) ? 'other' : 'rent';
        $this->chargeName = '';
        $this->amount = '';
        $this->validFrom = AddRecurringCharge::earliestMonth($this->lease)->toDateString();

        Flux::modal('add-charge')->show();
    }

    public function addCharge(AddRecurringCharge $addRecurringCharge): void
    {
        $this->authorize('update', $this->apartment);

        $this->validate([
            'chargeType' => ['required', Rule::enum(ChargeType::class)],
            'chargeName' => $this->chargeType === 'other' ? ['required', 'string', 'max:100'] : ['nullable'],
            'amount' => ['required', LeaseForm::moneyRule()],
            'validFrom' => ['required', 'date'],
        ], attributes: ['chargeName' => __('nazwa opłaty'), 'amount' => __('kwota'), 'validFrom' => __('od kiedy')]);

        $addRecurringCharge->handle($this->lease, Auth::user(), ChargeType::from($this->chargeType), trim($this->chargeName), Money::parse($this->amount), CarbonImmutable::parse($this->validFrom));

        Flux::modal('add-charge')->close();
        Flux::toast(variant: 'success', text: __('Opłata dodana.'));
        unset($this->lease, $this->statement);
    }

    // --- End of lease and deposit ---------------------------------------------------

    public function openEndLease(): void
    {
        $this->resetValidation();
        $this->endsOn = today()->max($this->lease->starts_on)->toDateString();
        Flux::modal('end-lease')->show();
    }

    public function endLease(EndLease $endLease): void
    {
        $this->authorize('update', $this->apartment);
        $this->validate(['endsOn' => ['required', 'date']], attributes: ['endsOn' => __('data zakończenia')]);

        $endLease->handle($this->lease, CarbonImmutable::parse($this->endsOn));

        Flux::modal('end-lease')->close();
        Flux::toast(variant: 'success', text: __('Najem zakończony. Opłaty za ostatni miesiąc zostały przeliczone.'));
        unset($this->lease, $this->statement);
    }

    public function openDepositReturn(): void
    {
        $this->resetValidation();
        $lease = $this->lease;
        $this->returnedOn = ($lease->deposit_returned_on ?? today())->toDateString();
        $this->returnedAmount = Money::toInput($lease->deposit_returned_amount ?? $lease->deposit_amount);
        $this->returnNotes = (string) $lease->deposit_return_notes;
        Flux::modal('deposit-return')->show();
    }

    public function saveDepositReturn(UpdateLease $updateLease): void
    {
        $this->authorize('update', $this->apartment);

        $this->validate([
            'returnedOn' => ['required', 'date'],
            'returnedAmount' => ['required', LeaseForm::moneyRule()],
            'returnNotes' => ['nullable', 'string', 'max:2000'],
        ], attributes: ['returnedOn' => __('data zwrotu'), 'returnedAmount' => __('zwrócona kwota')]);

        $returned = Money::parse($this->returnedAmount);
        if ($returned > (int) $this->lease->deposit_amount) {
            $this->addError('returnedAmount', __('Zwrócona kwota nie może być większa niż kaucja.'));

            return;
        }

        if ($returned < (int) $this->lease->deposit_amount && blank($this->returnNotes)) {
            $this->addError('returnNotes', __('Napisz krótko, dlaczego zwrócono mniej niż całą kaucję.'));

            return;
        }

        $updateLease->handle($this->lease, [
            'deposit_returned_on' => $this->returnedOn,
            'deposit_returned_amount' => $returned,
            'deposit_return_notes' => filled($this->returnNotes) ? trim($this->returnNotes) : null,
        ]);

        Flux::modal('deposit-return')->close();
        Flux::toast(variant: 'success', text: __('Zwrot kaucji zapisany.'));
        unset($this->lease);
    }
}; ?>

@php
    $lease = $this->lease;
    $fmt = fn (?int $amount) => \App\Support\Money::format((int) $amount, $lease?->currency ?? 'PLN');
@endphp

<div>
    @include('partials.apartment.header', ['current' => 'leases.show'])

    <div class="mx-auto w-full max-w-5xl">
        @if (! $lease)
            <div class="flex flex-col items-center rounded-2xl border-2 border-dashed border-stone-300 bg-tray px-6 py-16 text-center dark:border-stone-700">
                <div class="mb-4 flex size-16 items-center justify-center rounded-full bg-badge">
                    <flux:icon.key class="size-8 text-accent-content" />
                </div>
                <flux:heading size="lg">{{ __('To mieszkanie nie ma jeszcze najmu') }}</flux:heading>
                <flux:text class="mt-2 max-w-md text-base">
                    {{ __('Dodaj najemcę i warunki umowy. System sam będzie co miesiąc naliczał czynsz i pilnował wpłat.') }}
                </flux:text>
                <flux:button variant="primary" icon="plus" class="mt-6" :href="route('leases.create', $apartment)" wire:navigate>
                    {{ __('Dodaj najem') }}
                </flux:button>
            </div>
        @else
            @php
                $status = $lease->status();
                $statement = $this->statement;
                $balance = $statement->balance();
            @endphp

            {{-- Summary bar --}}
            <div class="mb-6 flex flex-wrap items-center justify-between gap-4">
                <div class="flex flex-wrap items-center gap-3">
                    <flux:badge :color="$status->color()">{{ $status->label() }}</flux:badge>
                    <flux:text class="text-base">
                        {{ __('od :date', ['date' => $lease->starts_on->translatedFormat('j F Y')]) }}
                        @if ($lease->effectiveEndsOn())
                            {{ __('do :date', ['date' => $lease->effectiveEndsOn()->translatedFormat('j F Y')]) }}
                        @else
                            · {{ __('na czas nieokreślony') }}
                        @endif
                    </flux:text>
                </div>

                <div class="flex flex-wrap gap-2">
                    <flux:button size="sm" icon="pencil-square" :href="route('leases.edit', [$apartment, $lease])" wire:navigate>{{ __('Edytuj umowę') }}</flux:button>
                    @if ($status !== \App\Enums\LeaseStatus::Ended)
                        <flux:button size="sm" icon="flag" wire:click="openEndLease">{{ __('Zakończ najem') }}</flux:button>
                    @endif
                </div>
            </div>

            <a href="{{ route('leases.ledger', [$apartment, $lease]) }}" wire:navigate class="mb-6 flex flex-wrap items-center justify-between gap-4 rounded-2xl border border-line bg-card p-5 transition hover:border-accent/40 hover:shadow-md">
                <div>
                    <flux:text>{{ __('Rozliczenia') }}</flux:text>
                    @if ($balance > 0)
                        <div class="mt-1 text-2xl font-semibold {{ $statement->overdueAmount() > 0 ? 'text-red-600 dark:text-red-400' : '' }}">
                            {{ __('Do zapłaty: :amount', ['amount' => $fmt($balance)]) }}
                        </div>
                        @if ($statement->overdueAmount() > 0)
                            <flux:text class="text-sm text-red-600! dark:text-red-400!">{{ __('w tym po terminie: :amount', ['amount' => $fmt($statement->overdueAmount())]) }}</flux:text>
                        @endif
                    @elseif ($balance < 0)
                        <div class="mt-1 text-2xl font-semibold text-accent-content">{{ __('Nadpłata: :amount', ['amount' => $fmt(-$balance)]) }}</div>
                    @else
                        <div class="mt-1 flex items-center gap-2 text-2xl font-semibold text-accent-content">
                            <flux:icon.check-circle /> {{ __('Wszystko rozliczone') }}
                        </div>
                    @endif
                </div>
                <span class="flex items-center gap-1 text-sm font-medium text-accent-content">{{ __('Zobacz rozliczenia') }} <flux:icon.arrow-right variant="micro" /></span>
            </a>

            <div class="grid gap-6 lg:grid-cols-2">
                {{-- Tenants --}}
                <flux:card>
                    <div class="mb-4 flex items-center justify-between gap-4">
                        <flux:heading size="lg">{{ $lease->tenants->count() > 1 ? __('Najemcy') : __('Najemca') }}</flux:heading>
                        <flux:button size="sm" variant="ghost" icon="user-plus" wire:click="openTenant">{{ __('Dodaj osobę') }}</flux:button>
                    </div>

                    <ul class="space-y-4">
                        @foreach ($lease->tenants as $tenant)
                            <li class="flex items-start gap-3" wire:key="tenant-{{ $tenant->id }}">
                                <flux:avatar size="sm" :name="$tenant->full_name" />
                                <div class="min-w-0 flex-1">
                                    <div class="flex flex-wrap items-center gap-2 font-medium">
                                        {{ $tenant->full_name }}
                                        @if ($tenant->is_primary && $lease->tenants->count() > 1)
                                            <flux:badge size="sm" color="green">{{ __('Główny kontakt') }}</flux:badge>
                                        @endif
                                    </div>
                                    @if ($tenant->email)
                                        <flux:link href="mailto:{{ $tenant->email }}" class="block text-sm">{{ $tenant->email }}</flux:link>
                                    @endif
                                    @if ($tenant->phone)
                                        <flux:link href="tel:{{ preg_replace('/[^\d+]/', '', $tenant->phone) }}" class="block text-sm">{{ $tenant->phone }}</flux:link>
                                    @endif
                                </div>
                                <flux:dropdown align="end">
                                    <flux:button size="sm" variant="ghost" icon="ellipsis-horizontal" :aria-label="__('Więcej')" />
                                    <flux:menu>
                                        <flux:menu.item icon="pencil-square" wire:click="openTenant({{ $tenant->id }})">{{ __('Edytuj dane') }}</flux:menu.item>
                                        @if (! $tenant->is_primary)
                                            <flux:menu.item icon="star" wire:click="makePrimary({{ $tenant->id }})">{{ __('Ustaw jako główny kontakt') }}</flux:menu.item>
                                        @endif
                                        @if ($lease->tenants->count() > 1)
                                            <flux:menu.item icon="trash" variant="danger" wire:click="removeTenant({{ $tenant->id }})" wire:confirm="{{ __('Usunąć :name z tego najmu?', ['name' => $tenant->full_name]) }}">{{ __('Usuń z najmu') }}</flux:menu.item>
                                        @endif
                                    </flux:menu>
                                </flux:dropdown>
                            </li>
                        @endforeach
                    </ul>
                </flux:card>

                {{-- Contract --}}
                <flux:card>
                    <flux:heading size="lg" class="mb-4">{{ __('Umowa') }}</flux:heading>
                    <dl class="divide-y divide-line">
                        @foreach (array_filter([
                            __('Początek') => $lease->starts_on->translatedFormat('j F Y'),
                            __('Koniec') => $lease->ends_on?->translatedFormat('j F Y') ?? __('czas nieokreślony'),
                            __('Zakończono') => $lease->terminated_on?->translatedFormat('j F Y'),
                            __('Termin płatności') => __('do :day. dnia miesiąca', ['day' => $lease->payment_due_day]),
                            __('Numer konta') => \App\Support\BankAccount::format($lease->bank_account) ?: '—',
                            __('Waluta') => $lease->currency,
                        ]) as $term => $value)
                            <div class="grid grid-cols-3 gap-4 py-2.5">
                                <dt class="text-sm text-stone-500 dark:text-stone-400">{{ $term }}</dt>
                                <dd class="col-span-2 font-medium break-words">{{ $value }}</dd>
                            </div>
                        @endforeach
                    </dl>
                    @if ($lease->notes)
                        <div class="mt-3 rounded-lg border border-line bg-tray p-3 text-sm whitespace-pre-line">{{ $lease->notes }}</div>
                    @endif
                </flux:card>

                {{-- Fixed charges --}}
                <flux:card class="lg:col-span-2">
                    <div class="mb-1 flex items-center justify-between gap-4">
                        <flux:heading size="lg">{{ __('Stałe opłaty co miesiąc') }}</flux:heading>
                        @if ($status !== \App\Enums\LeaseStatus::Ended)
                            <flux:button size="sm" variant="ghost" icon="plus" wire:click="openAddCharge">{{ __('Dodaj opłatę') }}</flux:button>
                        @endif
                    </div>
                    <flux:text class="mb-4 text-sm">{{ __('Naliczane automatycznie 1. dnia każdego miesiąca.') }}</flux:text>

                    @if ($lease->recurringCharges->isEmpty())
                        <flux:text>{{ __('Brak stałych opłat.') }}</flux:text>
                    @else
                        @php $thisMonth = today()->startOfMonth()->max($lease->starts_on->startOfMonth()); $monthlyTotal = 0; @endphp
                        <ul class="divide-y divide-line">
                            @foreach ($lease->recurringCharges as $charge)
                                @php
                                    $current = $charge->rateFor($thisMonth);
                                    $upcoming = $charge->rates->filter(fn ($rate) => $rate->valid_from->gt($thisMonth));
                                    $monthlyTotal += $current?->amount ?? 0;
                                @endphp
                                <li class="flex flex-wrap items-center gap-4 py-3" wire:key="charge-{{ $charge->id }}">
                                    <div class="min-w-0 flex-1">
                                        <div class="font-medium">{{ $charge->label }}</div>
                                        @foreach ($upcoming as $rate)
                                            <flux:text class="text-sm">
                                                {{ $rate->amount === 0
                                                    ? __('od :date przestaje być naliczana', ['date' => $rate->valid_from->translatedFormat('j F Y')])
                                                    : __('od :date: :amount', ['date' => $rate->valid_from->translatedFormat('j F Y'), 'amount' => $fmt($rate->amount)]) }}
                                            </flux:text>
                                        @endforeach
                                    </div>
                                    <div class="text-right">
                                        <div class="font-semibold">{{ $current && $current->amount > 0 ? $fmt($current->amount) : '—' }}</div>
                                        <flux:text class="text-xs">{{ __('miesięcznie') }}</flux:text>
                                    </div>
                                    @if ($status !== \App\Enums\LeaseStatus::Ended)
                                        <flux:button size="sm" wire:click="openChangeAmount({{ $charge->id }})">{{ __('Zmień kwotę') }}</flux:button>
                                    @endif
                                </li>
                            @endforeach
                        </ul>
                        @if ($lease->recurringCharges->count() > 1)
                            <div class="mt-2 flex justify-between border-t border-line pt-3 font-semibold">
                                <span>{{ __('Razem miesięcznie') }}</span>
                                <span>{{ $fmt($monthlyTotal) }}</span>
                            </div>
                        @endif
                    @endif
                </flux:card>

                {{-- Notifications --}}
                @php
                    $digest = $this->digest;
                    $emails = $lease->tenantEmails();
                    $pendingCount = $digest->newCharges->count() + $digest->changedCharges->count();
                @endphp
                <flux:card class="lg:col-span-2">
                    <div class="flex flex-wrap items-start justify-between gap-4">
                        <div class="min-w-0 flex-1">
                            <div class="flex items-center gap-2">
                                <flux:heading size="lg">{{ __('Powiadomienia e-mail dla najemcy') }}</flux:heading>
                                <flux:badge size="sm" :color="$lease->notify_tenants ? 'green' : 'zinc'">{{ $lease->notify_tenants ? __('włączone') : __('wyłączone') }}</flux:badge>
                            </div>
                            <flux:text class="mt-1 text-sm">{{ __('Jedna wiadomość dziennie po 16:00, tylko gdy są nowe opłaty, rachunki lub zmiany kwot. Bez załączników. Właściciele dostają kopię.') }}</flux:text>

                            @if ($lease->notify_tenants)
                                @if ($emails === [])
                                    <div class="mt-3 rounded-lg border border-amber-200 bg-amber-50 p-3 text-sm text-amber-800 dark:border-amber-900/60 dark:bg-amber-950/30 dark:text-amber-300">
                                        {{ __('Najemca nie ma wpisanego adresu e-mail, więc nie dostanie powiadomień. Uzupełnij go w danych najemcy.') }}
                                    </div>
                                @else
                                    <flux:text class="mt-3 text-sm">
                                        {{ __('Odbiorcy: :emails', ['emails' => implode(', ', $emails)]) }}<br>
                                        {{ __('Kopia do właścicieli: :emails', ['emails' => $apartment->owners->pluck('email')->join(', ')]) }}<br>
                                        @if ($pendingCount > 0)
                                            <strong>{{ trans_choice('Dziś po 16:00 wyślemy :count nową pozycję.|Dziś po 16:00 wyślemy :count nowe pozycje.|Dziś po 16:00 wyślemy :count nowych pozycji.', $pendingCount) }}</strong>
                                        @else
                                            {{ __('Nie ma nic nowego do wysłania.') }}
                                        @endif
                                        @if ($lease->last_notified_at)
                                            {{ __('Ostatnia wiadomość: :date.', ['date' => $lease->last_notified_at->translatedFormat('j F Y, H:i')]) }}
                                        @endif
                                    </flux:text>
                                @endif
                            @endif
                        </div>

                        <div class="flex flex-wrap gap-2">
                            @if ($lease->notify_tenants && $emails !== [])
                                <flux:button size="sm" icon="eye" :href="route('leases.notification-preview', [$apartment, $lease])" target="_blank">{{ __('Podgląd wiadomości') }}</flux:button>
                            @endif
                            <flux:button size="sm" wire:click="toggleNotifications">{{ $lease->notify_tenants ? __('Wyłącz') : __('Włącz') }}</flux:button>
                        </div>
                    </div>
                </flux:card>

                {{-- Deposit --}}
                <flux:card class="lg:col-span-2">
                    <div class="flex flex-wrap items-start justify-between gap-4">
                        <div>
                            <flux:heading size="lg">{{ __('Kaucja') }}</flux:heading>
                            @if ($lease->deposit_amount === null)
                                <flux:text class="mt-2">{{ __('Bez kaucji.') }}</flux:text>
                            @else
                                <div class="mt-2 text-xl font-semibold">{{ $fmt($lease->deposit_amount) }}</div>
                                <flux:text class="text-sm">{{ $lease->deposit_method?->label() }} · {{ __('nie jest wliczana do rozliczeń') }}</flux:text>

                                @if ($lease->deposit_returned_on)
                                    <div class="mt-3 rounded-lg border border-line bg-tray p-3 text-sm">
                                        <div class="font-medium">
                                            {{ __('Zwrócono :amount dnia :date', ['amount' => $fmt($lease->deposit_returned_amount), 'date' => $lease->deposit_returned_on->translatedFormat('j F Y')]) }}
                                            @if ($lease->deposit_returned_amount < $lease->deposit_amount)
                                                <span class="text-stone-500">({{ __('zatrzymano :amount', ['amount' => $fmt($lease->deposit_amount - $lease->deposit_returned_amount)]) }})</span>
                                            @endif
                                        </div>
                                        @if ($lease->deposit_return_notes)
                                            <div class="mt-1 whitespace-pre-line text-stone-600 dark:text-stone-300">{{ $lease->deposit_return_notes }}</div>
                                        @endif
                                    </div>
                                @else
                                    <flux:badge size="sm" color="amber" class="mt-2">{{ __('Jeszcze nie zwrócona') }}</flux:badge>
                                @endif
                            @endif
                        </div>

                        @if ($lease->deposit_amount !== null)
                            <flux:button size="sm" icon="arrow-uturn-left" wire:click="openDepositReturn">
                                {{ $lease->deposit_returned_on ? __('Popraw zwrot kaucji') : __('Zapisz zwrot kaucji') }}
                            </flux:button>
                        @endif
                    </div>
                </flux:card>
            </div>

            {{-- Other leases --}}
            <section class="mt-10">
                <div class="mb-4 flex flex-wrap items-center justify-between gap-4">
                    <flux:heading size="lg">{{ __('Inne najmy tego mieszkania') }}</flux:heading>
                    <flux:button size="sm" icon="plus" :href="route('leases.create', $apartment)" wire:navigate>{{ __('Dodaj kolejny najem') }}</flux:button>
                </div>

                @if ($this->otherLeases->isEmpty())
                    <flux:text class="text-sm">{{ __('Brak innych najmów. Kolejny najem możesz dodać, gdy obecny ma ustaloną datę zakończenia.') }}</flux:text>
                @else
                    <ul class="divide-y divide-line rounded-2xl border border-line bg-card">
                        @foreach ($this->otherLeases as $other)
                            <li wire:key="other-lease-{{ $other->id }}">
                                <a href="{{ route('leases.show', [$apartment, $other]) }}" wire:navigate class="flex flex-wrap items-center gap-3 p-4 hover:bg-tray">
                                    <flux:badge size="sm" :color="$other->status()->color()">{{ $other->status()->label() }}</flux:badge>
                                    <span class="font-medium">{{ $other->tenantNames() }}</span>
                                    <flux:text class="text-sm">
                                        {{ $other->starts_on->format('d.m.Y') }} – {{ $other->effectiveEndsOn()?->format('d.m.Y') ?? __('bezterminowo') }}
                                    </flux:text>
                                </a>
                            </li>
                        @endforeach
                    </ul>
                @endif
            </section>

            {{-- Modals --}}
            <flux:modal name="tenant" class="w-full max-w-lg">
                <form wire:submit="saveTenant" class="space-y-6">
                    <flux:heading size="lg">{{ $editingTenantId ? __('Dane najemcy') : __('Dodaj osobę do najmu') }}</flux:heading>
                    <div class="grid gap-4 sm:grid-cols-2">
                        <flux:input wire:model="tenantFirstName" :label="__('Imię')" />
                        <flux:input wire:model="tenantLastName" :label="__('Nazwisko')" />
                        <flux:input wire:model="tenantEmail" type="email" :label="__('E-mail')" :badge="__('Opcjonalnie')" />
                        <flux:input wire:model="tenantPhone" type="tel" :label="__('Telefon')" :badge="__('Opcjonalnie')" :description="__('Nie jest potrzebny do działania aplikacji.')" />
                    </div>
                    <div class="flex justify-end gap-2">
                        <flux:modal.close><flux:button variant="ghost">{{ __('Anuluj') }}</flux:button></flux:modal.close>
                        <flux:button type="submit" variant="primary">{{ __('Zapisz') }}</flux:button>
                    </div>
                </form>
            </flux:modal>

            <flux:modal name="change-amount" class="w-full max-w-md">
                @if ($chargeId && ($charge = $lease->recurringCharges->firstWhere('id', $chargeId)))
                    <form wire:submit="changeAmount" class="space-y-6">
                        <div>
                            <flux:heading size="lg">{{ __('Zmień kwotę: :name', ['name' => $charge->label]) }}</flux:heading>
                            <flux:text class="mt-2">{{ __('Nowa kwota zacznie obowiązywać od wybranego miesiąca. Miesiące już naliczone się nie zmienią. Wpisz 0, aby przestać naliczać tę opłatę.') }}</flux:text>
                        </div>
                        @include('partials.money-input', ['model' => 'amount', 'label' => __('Nowa kwota miesięcznie'), 'currency' => $lease->currency, 'autofocus' => true])
                        <flux:select wire:model="validFrom" :label="__('Od kiedy')" class="max-w-64">
                            @foreach ($this->monthOptions(\App\Actions\Leases\ChangeChargeAmount::earliestMonth($charge)) as $value => $label)
                                <flux:select.option :value="$value">{{ $label }}</flux:select.option>
                            @endforeach
                        </flux:select>
                        <div class="flex justify-end gap-2">
                            <flux:modal.close><flux:button variant="ghost">{{ __('Anuluj') }}</flux:button></flux:modal.close>
                            <flux:button type="submit" variant="primary">{{ __('Zapisz nową kwotę') }}</flux:button>
                        </div>
                    </form>
                @endif
            </flux:modal>

            <flux:modal name="add-charge" class="w-full max-w-md">
                <form wire:submit="addCharge" class="space-y-6">
                    <flux:heading size="lg">{{ __('Dodaj stałą opłatę') }}</flux:heading>
                    <flux:radio.group wire:model.live="chargeType" :label="__('Rodzaj opłaty')">
                        @foreach (\App\Enums\ChargeType::cases() as $type)
                            <flux:radio :value="$type->value" :label="$type->label()" />
                        @endforeach
                    </flux:radio.group>
                    @if ($chargeType === 'other')
                        <flux:input wire:model="chargeName" :label="__('Nazwa opłaty')" :placeholder="__('np. Miejsce parkingowe')" />
                    @endif
                    @include('partials.money-input', ['model' => 'amount', 'label' => __('Kwota miesięcznie'), 'currency' => $lease->currency])
                    <flux:select wire:model="validFrom" :label="__('Od kiedy')" class="max-w-64">
                        @foreach ($this->monthOptions(\App\Actions\Leases\AddRecurringCharge::earliestMonth($lease)) as $value => $label)
                            <flux:select.option :value="$value">{{ $label }}</flux:select.option>
                        @endforeach
                    </flux:select>
                    <div class="flex justify-end gap-2">
                        <flux:modal.close><flux:button variant="ghost">{{ __('Anuluj') }}</flux:button></flux:modal.close>
                        <flux:button type="submit" variant="primary">{{ __('Dodaj opłatę') }}</flux:button>
                    </div>
                </form>
            </flux:modal>

            <flux:modal name="end-lease" class="w-full max-w-md">
                <form wire:submit="endLease" class="space-y-6">
                    <div>
                        <flux:heading size="lg">{{ __('Zakończ najem') }}</flux:heading>
                        <flux:text class="mt-2">{{ __('Podaj ostatni dzień najmu. Opłaty za ostatni miesiąc przeliczymy proporcjonalnie do dni, a kolejne miesiące nie będą już naliczane.') }}</flux:text>
                    </div>
                    <flux:input type="date" wire:model="endsOn" :label="__('Ostatni dzień najmu')" class="max-w-56" />
                    <flux:error name="ends_on" />
                    <div class="flex justify-end gap-2">
                        <flux:modal.close><flux:button variant="ghost">{{ __('Anuluj') }}</flux:button></flux:modal.close>
                        <flux:button type="submit" variant="danger">{{ __('Zakończ najem') }}</flux:button>
                    </div>
                </form>
            </flux:modal>

            <flux:modal name="deposit-return" class="w-full max-w-md">
                <form wire:submit="saveDepositReturn" class="space-y-6">
                    <div>
                        <flux:heading size="lg">{{ __('Zwrot kaucji') }}</flux:heading>
                        <flux:text class="mt-2">{{ __('Kaucja wynosiła :amount. Jeśli zwracasz mniej, napisz dlaczego.', ['amount' => $fmt($lease->deposit_amount)]) }}</flux:text>
                    </div>
                    <flux:input type="date" wire:model="returnedOn" :label="__('Data zwrotu')" class="max-w-56" />
                    @include('partials.money-input', ['model' => 'returnedAmount', 'label' => __('Zwrócona kwota'), 'currency' => $lease->currency])
                    <flux:textarea wire:model="returnNotes" :label="__('Uwagi')" :placeholder="__('Np. potrącono 300 zł za naprawę drzwi')" rows="3" />
                    <div class="flex justify-end gap-2">
                        <flux:modal.close><flux:button variant="ghost">{{ __('Anuluj') }}</flux:button></flux:modal.close>
                        <flux:button type="submit" variant="primary">{{ __('Zapisz') }}</flux:button>
                    </div>
                </form>
            </flux:modal>
        @endif
    </div>
</div>
