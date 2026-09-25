<?php

use App\Actions\Leases\CreateLease;
use App\Enums\ChargeType;
use App\Livewire\Forms\LeaseForm;
use App\Models\Apartment;
use App\Support\Money;
use Carbon\CarbonImmutable;
use Flux\Flux;
use Illuminate\Support\Facades\Auth;
use Livewire\Attributes\Title;
use Livewire\Component;

new #[Title('Nowy najem')] class extends Component {
    public Apartment $apartment;

    public LeaseForm $form;

    public int $step = 1;

    /** @var list<array{first_name: string, last_name: string, email: string, phone: string, locale: string}> */
    public array $tenants = [];

    public string $rent = '';

    public string $adminFee = '';

    /** @var list<array{name: string, amount: string}> */
    public array $otherCharges = [];

    public function mount(Apartment $apartment): void
    {
        $this->authorize('update', $apartment);

        $this->apartment = $apartment;
        $this->tenants = [$this->emptyTenant()];
        $this->form->starts_on = now()->toDateString();

        // Suggest the bank account from the previous lease.
        if ($previous = $apartment->leases()->whereNotNull('bank_account')->first()) {
            $this->form->bank_account = \App\Support\BankAccount::format($previous->bank_account);
            $this->form->currency = $previous->currency;
        }
    }

    /**
     * @return array{first_name: string, last_name: string, email: string, phone: string, locale: string}
     */
    protected function emptyTenant(): array
    {
        return ['first_name' => '', 'last_name' => '', 'email' => '', 'phone' => '', 'locale' => app()->getLocale()];
    }

    public function addTenant(): void
    {
        $this->tenants[] = $this->emptyTenant();
    }

    public function removeTenant(int $index): void
    {
        unset($this->tenants[$index]);
        $this->tenants = array_values($this->tenants);
    }

    public function addOtherCharge(): void
    {
        $this->otherCharges[] = ['name' => '', 'amount' => ''];
    }

    public function removeOtherCharge(int $index): void
    {
        unset($this->otherCharges[$index]);
        $this->otherCharges = array_values($this->otherCharges);
    }

    protected function validateStep(int $step): void
    {
        match ($step) {
            1 => $this->validate([
                'tenants.*.first_name' => ['required', 'string', 'max:100'],
                'tenants.*.last_name' => ['required', 'string', 'max:100'],
                'tenants.*.email' => ['nullable', 'email', 'max:255'],
                'tenants.*.phone' => ['nullable', 'string', 'max:40'],
                'tenants.*.locale' => ['required', \Illuminate\Validation\Rule::in(\App\Support\Locales::codes())],
            ], attributes: [
                'tenants.*.first_name' => __('imię'),
                'tenants.*.last_name' => __('nazwisko'),
                'tenants.*.email' => __('e-mail'),
                'tenants.*.phone' => __('telefon'),
            ]),
            2 => $this->form->validate($this->form->termsRules()),
            3 => $this->form->validate($this->form->depositRules()),
            4 => $this->validate([
                'rent' => ['nullable', LeaseForm::moneyRule()],
                'adminFee' => ['nullable', LeaseForm::moneyRule()],
                'otherCharges.*.name' => ['required', 'string', 'max:100'],
                'otherCharges.*.amount' => ['required', LeaseForm::moneyRule()],
            ], attributes: [
                'rent' => __('opłata za mieszkanie'),
                'adminFee' => __('opłata do administracji'),
                'otherCharges.*.name' => __('nazwa opłaty'),
                'otherCharges.*.amount' => __('kwota'),
            ]),
            default => null,
        };
    }

    public function next(): void
    {
        $this->tenants = array_map(fn ($t) => array_map('trim', $t), $this->tenants);
        $this->validateStep($this->step);
        $this->step = min($this->step + 1, 5);
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

    /**
     * @return list<array{type: ChargeType, name: ?string, amount: int}>
     */
    protected function charges(): array
    {
        $charges = [];

        if (($amount = Money::parse($this->rent)) > 0) {
            $charges[] = ['type' => ChargeType::Rent, 'name' => null, 'amount' => $amount];
        }
        if (($amount = Money::parse($this->adminFee)) > 0) {
            $charges[] = ['type' => ChargeType::AdminFee, 'name' => null, 'amount' => $amount];
        }
        foreach ($this->otherCharges as $other) {
            $charges[] = ['type' => ChargeType::Other, 'name' => trim($other['name']), 'amount' => (int) Money::parse($other['amount'])];
        }

        return $charges;
    }

    public function monthlyTotal(): int
    {
        return array_sum(array_column($this->charges(), 'amount'));
    }

    public function save(CreateLease $createLease): void
    {
        $this->authorize('update', $this->apartment);

        foreach ([1, 2, 3, 4] as $step) {
            try {
                $this->validateStep($step);
            } catch (\Illuminate\Validation\ValidationException $e) {
                $this->step = $step;
                throw $e;
            }
        }

        $tenants = array_map(fn ($t) => [
            'first_name' => $t['first_name'],
            'last_name' => $t['last_name'],
            'email' => $t['email'] !== '' ? mb_strtolower($t['email']) : null,
            'phone' => $t['phone'] !== '' ? $t['phone'] : null,
            'locale' => $t['locale'],
        ], $this->tenants);

        try {
            $lease = $createLease->handle($this->apartment, Auth::user(), $this->form->data(), $tenants, $this->charges());
        } catch (\Illuminate\Validation\ValidationException $e) {
            $this->step = 2;
            $this->addError('form.starts_on', $e->validator->errors()->first());

            return;
        }

        Flux::toast(variant: 'success', text: __('Najem został zapisany.'));

        $this->redirectRoute('leases.show', [$this->apartment, $lease], navigate: true);
    }
}; ?>

@php
    $currency = $form->currency;
    $fmt = fn (?int $amount) => \App\Support\Money::format((int) $amount, $currency);
@endphp

<div>
    @include('partials.apartment.header', ['current' => 'leases.show'])

    <div class="mx-auto w-full max-w-2xl">
        <flux:heading size="lg" class="mb-6">{{ __('Nowy najem') }}</flux:heading>

        @include('partials.steps', ['steps' => [__('Najemca'), __('Umowa'), __('Kaucja'), __('Opłaty'), __('Podsumowanie')], 'current' => $step])

        <flux:card class="bg-tray p-6 sm:p-8">
            @if ($step === 1)
                <form wire:submit="next" wire:key="step-1">
                    <flux:heading size="lg">{{ __('Kto wynajmuje mieszkanie?') }}</flux:heading>
                    <flux:text class="mb-6 mt-1">{{ __('Wpisz dane najemcy. Jeśli umowę podpisało kilka osób, dodaj każdą z nich – pierwsza będzie głównym kontaktem.') }}</flux:text>

                    <div class="space-y-6">
                        @foreach ($tenants as $index => $tenant)
                            <div class="space-y-4 rounded-xl border border-line bg-card p-4" wire:key="tenant-{{ $index }}">
                                <div class="flex items-center justify-between">
                                    <flux:heading>{{ $index === 0 ? __('Główny najemca') : __('Kolejna osoba') }}</flux:heading>
                                    @if ($index > 0)
                                        <flux:button size="sm" variant="ghost" icon="x-mark" wire:click="removeTenant({{ $index }})">{{ __('Usuń') }}</flux:button>
                                    @endif
                                </div>
                                <div class="grid gap-4 sm:grid-cols-2">
                                    <flux:input wire:model="tenants.{{ $index }}.first_name" :label="__('Imię')" autocomplete="off" />
                                    <flux:input wire:model="tenants.{{ $index }}.last_name" :label="__('Nazwisko')" autocomplete="off" />
                                    <flux:input wire:model="tenants.{{ $index }}.email" type="email" :label="__('E-mail')" :badge="__('Opcjonalnie')" autocomplete="off" />
                                    <flux:input wire:model="tenants.{{ $index }}.phone" type="tel" :label="__('Telefon')" :badge="__('Opcjonalnie')" :description="__('Nie jest potrzebny do działania aplikacji.')" autocomplete="off" />
                                    <flux:select wire:model="tenants.{{ $index }}.locale" :label="__('Język wiadomości')" :description="__('W tym języku najemca dostanie e-maile.')">
                                        @foreach (\App\Support\Locales::SUPPORTED as $code => $name)
                                            <flux:select.option :value="$code">{{ $name }}</flux:select.option>
                                        @endforeach
                                    </flux:select>
                                </div>
                            </div>
                        @endforeach
                    </div>

                    <flux:button size="sm" icon="user-plus" class="mt-4" wire:click="addTenant">{{ __('Dodaj kolejną osobę') }}</flux:button>

                    <div class="mt-8 flex justify-between gap-3">
                        <flux:button :href="route('leases.current', $apartment)" wire:navigate variant="ghost">{{ __('Anuluj') }}</flux:button>
                        <flux:button type="submit" variant="primary" icon:trailing="arrow-right">{{ __('Dalej') }}</flux:button>
                    </div>
                </form>
            @elseif ($step === 2)
                <form wire:submit="next" wire:key="step-2">
                    <flux:heading size="lg">{{ __('Warunki umowy') }}</flux:heading>
                    <flux:text class="mb-6 mt-1">{{ __('Przepisz dane z umowy najmu.') }}</flux:text>

                    @include('partials.lease.terms-fields')

                    <div class="mt-8 flex justify-between gap-3">
                        <flux:button wire:click="back" variant="ghost" icon="arrow-left">{{ __('Wstecz') }}</flux:button>
                        <flux:button type="submit" variant="primary" icon:trailing="arrow-right">{{ __('Dalej') }}</flux:button>
                    </div>
                </form>
            @elseif ($step === 3)
                <form wire:submit="next" wire:key="step-3">
                    <flux:heading size="lg">{{ __('Kaucja') }}</flux:heading>
                    <flux:text class="mb-6 mt-1">{{ __('Jeśli najemca wpłacił kaucję, zapisz jej kwotę.') }}</flux:text>

                    @include('partials.lease.deposit-fields')

                    <div class="mt-8 flex justify-between gap-3">
                        <flux:button wire:click="back" variant="ghost" icon="arrow-left">{{ __('Wstecz') }}</flux:button>
                        <flux:button type="submit" variant="primary" icon:trailing="arrow-right">{{ __('Dalej') }}</flux:button>
                    </div>
                </form>
            @elseif ($step === 4)
                <form wire:submit="next" wire:key="step-4">
                    <flux:heading size="lg">{{ __('Stałe opłaty co miesiąc') }}</flux:heading>
                    <flux:text class="mb-6 mt-1">{{ __('Te kwoty będą naliczane automatycznie 1. dnia każdego miesiąca. Rachunki za media dodasz później, gdy przyjdą faktury.') }}</flux:text>

                    <div class="space-y-6">
                        @include('partials.money-input', ['model' => 'rent', 'label' => __('Opłata za mieszkanie'), 'currency' => $currency, 'description' => __('Czynsz dla Ciebie jako wynajmującego.')])
                        @include('partials.money-input', ['model' => 'adminFee', 'label' => __('Opłata do administracji / wspólnoty'), 'currency' => $currency, 'description' => __('Zostaw puste, jeśli najemca jej nie płaci.')])

                        @foreach ($otherCharges as $index => $other)
                            <div class="flex items-end gap-3 rounded-xl border border-line bg-card p-4" wire:key="other-{{ $index }}">
                                <flux:input wire:model="otherCharges.{{ $index }}.name" :label="__('Nazwa opłaty')" :placeholder="__('np. Miejsce parkingowe')" class="flex-1" />
                                <flux:input.group class="max-w-40">
                                    <flux:input wire:model="otherCharges.{{ $index }}.amount" inputmode="decimal" placeholder="0" />
                                    <flux:input.group.suffix>{{ \App\Support\Currencies::symbol($currency) }}</flux:input.group.suffix>
                                </flux:input.group>
                                <flux:button variant="ghost" icon="x-mark" wire:click="removeOtherCharge({{ $index }})" :aria-label="__('Usuń')" />
                            </div>
                            <flux:error name="otherCharges.{{ $index }}.name" />
                            <flux:error name="otherCharges.{{ $index }}.amount" />
                        @endforeach

                        <flux:button size="sm" icon="plus" wire:click="addOtherCharge">{{ __('Dodaj inną opłatę') }}</flux:button>
                    </div>

                    <div class="mt-8 flex justify-between gap-3">
                        <flux:button wire:click="back" variant="ghost" icon="arrow-left">{{ __('Wstecz') }}</flux:button>
                        <flux:button type="submit" variant="primary" icon:trailing="arrow-right">{{ __('Dalej') }}</flux:button>
                    </div>
                </form>
            @else
                @php
                    $startsOn = \Carbon\CarbonImmutable::parse($form->starts_on);
                    $prorated = $startsOn->day !== 1;
                @endphp
                <div wire:key="step-5">
                    <flux:heading size="lg">{{ __('Sprawdź, czy wszystko się zgadza') }}</flux:heading>
                    <flux:text class="mb-6 mt-1">{{ __('Jeśli coś trzeba poprawić, kliknij „Zmień”.') }}</flux:text>

                    <div class="divide-y divide-line rounded-xl border border-line bg-card">
                        <div class="flex items-start justify-between gap-4 p-4">
                            <div>
                                <flux:text class="text-sm">{{ __('Najemca') }}</flux:text>
                                @foreach ($tenants as $tenant)
                                    <div class="mt-1 font-medium">{{ $tenant['first_name'] }} {{ $tenant['last_name'] }}</div>
                                    <flux:text class="text-sm">{{ collect([$tenant['email'], $tenant['phone']])->filter()->join(' · ') }}</flux:text>
                                @endforeach
                            </div>
                            <flux:button size="sm" variant="ghost" wire:click="goTo(1)">{{ __('Zmień') }}</flux:button>
                        </div>
                        <div class="flex items-start justify-between gap-4 p-4">
                            <div>
                                <flux:text class="text-sm">{{ __('Umowa') }}</flux:text>
                                <div class="mt-1 font-medium">
                                    {{ __('od :date', ['date' => $startsOn->translatedFormat('j F Y')]) }}
                                    {{ $form->open_ended ? __('– na czas nieokreślony') : __('do :date', ['date' => \Carbon\CarbonImmutable::parse($form->ends_on)->translatedFormat('j F Y')]) }}
                                </div>
                                <flux:text class="text-sm">
                                    {{ __('Płatność do :day. dnia miesiąca', ['day' => $form->payment_due_day]) }} · {{ $currency }}
                                    @if ($form->bank_account) · {{ __('konto') }} {{ $form->bank_account }} @endif
                                </flux:text>
                            </div>
                            <flux:button size="sm" variant="ghost" wire:click="goTo(2)">{{ __('Zmień') }}</flux:button>
                        </div>
                        <div class="flex items-start justify-between gap-4 p-4">
                            <div>
                                <flux:text class="text-sm">{{ __('Kaucja') }}</flux:text>
                                <div class="mt-1 font-medium">
                                    @if ($form->has_deposit)
                                        {{ $fmt(\App\Support\Money::parse($form->deposit_amount)) }} · {{ \App\Enums\PaymentMethod::from($form->deposit_method)->label() }}
                                    @else
                                        {{ __('Bez kaucji') }}
                                    @endif
                                </div>
                            </div>
                            <flux:button size="sm" variant="ghost" wire:click="goTo(3)">{{ __('Zmień') }}</flux:button>
                        </div>
                        <div class="flex items-start justify-between gap-4 p-4">
                            <div class="flex-1">
                                <flux:text class="text-sm">{{ __('Stałe opłaty co miesiąc') }}</flux:text>
                                @forelse ($this->charges() as $charge)
                                    <div class="mt-1 flex justify-between gap-4">
                                        <span>{{ $charge['type'] === \App\Enums\ChargeType::Other ? $charge['name'] : $charge['type']->label() }}</span>
                                        <span class="font-medium">{{ $fmt($charge['amount']) }}</span>
                                    </div>
                                @empty
                                    <div class="mt-1 font-medium">{{ __('Brak stałych opłat') }}</div>
                                @endforelse
                                @if (count($this->charges()) > 1)
                                    <div class="mt-2 flex justify-between gap-4 border-t border-line pt-2 font-semibold">
                                        <span>{{ __('Razem miesięcznie') }}</span>
                                        <span>{{ $fmt($this->monthlyTotal()) }}</span>
                                    </div>
                                @endif
                            </div>
                            <flux:button size="sm" variant="ghost" wire:click="goTo(4)">{{ __('Zmień') }}</flux:button>
                        </div>
                    </div>

                    @if ($this->charges() !== [])
                        <flux:callout icon="information-circle" class="mt-6">
                            <flux:callout.text>
                                @if ($startsOn->lte(today()))
                                    {{ __('Po zapisaniu od razu naliczymy opłaty od :date do bieżącego miesiąca.', ['date' => $startsOn->translatedFormat('j F Y')]) }}
                                @else
                                    {{ __('Pierwsze opłaty naliczymy :date, gdy najem się rozpocznie.', ['date' => $startsOn->translatedFormat('j F Y')]) }}
                                @endif
                                @if ($prorated)
                                    {{ __('Za pierwszy, niepełny miesiąc opłaty będą proporcjonalne do liczby dni.') }}
                                @endif
                            </flux:callout.text>
                        </flux:callout>
                    @endif

                    <flux:error name="form.starts_on" class="mt-4" />

                    <div class="mt-8 flex justify-between gap-3">
                        <flux:button wire:click="back" variant="ghost" icon="arrow-left">{{ __('Wstecz') }}</flux:button>
                        <flux:button wire:click="save" variant="primary" icon="check">{{ __('Zapisz najem') }}</flux:button>
                    </div>
                </div>
            @endif
        </flux:card>
    </div>
</div>
