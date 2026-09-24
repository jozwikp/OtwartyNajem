<?php

namespace App\Support;

use App\Enums\BillCategory;
use App\Enums\ChargeType;
use App\Enums\PaymentMethod;
use App\Models\Apartment;
use App\Models\Bill;
use App\Models\Lease;
use App\Models\LeaseTenant;
use App\Models\RecurringChargeRate;
use Carbon\CarbonImmutable;
use Illuminate\Support\Number;
use Spatie\Activitylog\Models\Activity;

/**
 * Turns raw activity log entries into plain Polish sentences for the change history.
 */
class ActivityPresenter
{
    protected const MONEY_ATTRIBUTES = ['amount', 'deposit_amount', 'deposit_returned_amount', 'total_amount', 'tenant_amount'];

    protected const DATE_ATTRIBUTES = ['starts_on', 'ends_on', 'terminated_on', 'deposit_returned_on', 'issued_on', 'period_from', 'period_to', 'due_on', 'booked_on', 'valid_from'];

    /** @var array<int, Apartment|null> */
    protected static array $apartments = [];

    public function __construct(public Activity $activity) {}

    public function key(): string
    {
        return $this->activity->log_name.'.'.$this->activity->event;
    }

    public function icon(): string
    {
        return match ($this->activity->log_name) {
            'leases' => $this->activity->event === 'created' ? 'key' : 'document-text',
            'lease_tenants' => 'user',
            'recurring_charges', 'recurring_charge_rates' => 'calendar-days',
            'bills' => 'receipt-percent',
            'ledger_entries' => $this->attribute('kind') === 'payment' ? 'banknotes' : 'calculator',
            default => match ($this->activity->event) {
                'created' => 'plus-circle',
                'updated' => 'pencil-square',
                'deleted' => 'trash',
                'restored' => 'arrow-uturn-left',
                'owner_invited', 'invitation_resent' => 'envelope',
                'invitation_cancelled', 'invitation_declined' => 'x-circle',
                'owner_joined' => 'user-plus',
                'owner_removed', 'owner_left' => 'user-minus',
                'login' => 'arrow-right-end-on-rectangle',
                'logout' => 'arrow-left-start-on-rectangle',
                'password_changed' => 'key',
                default => 'information-circle',
            },
        };
    }

    public function title(): string
    {
        $email = $this->activity->getProperty('email');
        $name = $this->activity->getProperty('name');

        return match ($this->key()) {
            'apartments.created' => __('Dodano mieszkanie'),
            'apartments.updated' => __('Zmieniono dane mieszkania'),
            'apartments.deleted' => __('Usunięto mieszkanie'),
            'apartments.restored' => __('Przywrócono mieszkanie'),
            'apartments.owner_invited' => __('Wysłano zaproszenie do :email', ['email' => $email]),
            'apartments.invitation_resent' => __('Ponownie wysłano zaproszenie do :email', ['email' => $email]),
            'apartments.invitation_cancelled' => __('Anulowano zaproszenie dla :email', ['email' => $email]),
            'apartments.invitation_declined' => __('Zaproszenie dla :email zostało odrzucone', ['email' => $email]),
            'apartments.owner_joined' => __('Nowy współwłaściciel: :name', ['name' => $name]),
            'apartments.owner_removed' => __('Usunięto współwłaściciela: :name', ['name' => $name]),
            'apartments.owner_left' => __('Rezygnacja ze współwłasności: :name', ['name' => $name]),

            'leases.created' => __('Dodano najem od :date', ['date' => $this->formatValue('starts_on', $this->attribute('starts_on'))]),
            'leases.updated' => $this->leaseUpdateTitle(),
            'leases.deleted' => __('Usunięto najem'),

            'lease_tenants.created' => __('Dodano najemcę: :name', ['name' => $this->tenantName()]),
            'lease_tenants.updated' => __('Zmieniono dane najemcy: :name', ['name' => $this->tenantName()]),
            'lease_tenants.deleted' => __('Usunięto najemcę: :name', ['name' => $this->tenantName()]),

            'recurring_charges.created' => __('Dodano stałą opłatę: :name', ['name' => $this->chargeName()]),
            'recurring_charges.deleted' => __('Usunięto stałą opłatę: :name', ['name' => $this->chargeName()]),
            'recurring_charge_rates.created', 'recurring_charge_rates.updated' => $this->rateTitle(),

            'bills.created' => __('Dodano rachunek: :category, :amount', [
                'category' => $this->formatValue('category', $this->attribute('category')),
                'amount' => $this->formatValue('tenant_amount', $this->attribute('tenant_amount')),
            ]),
            'bills.updated' => __('Zmieniono rachunek'),
            'bills.deleted' => __('Usunięto rachunek: :category', ['category' => $this->formatValue('category', $this->attribute('category'))]),

            'ledger_entries.created' => $this->attribute('kind') === 'payment'
                ? __('Zapisano wpłatę: :amount', ['amount' => $this->formatValue('amount', $this->attribute('amount'))])
                : __('Naliczono: :description – :amount', ['description' => $this->attribute('description'), 'amount' => $this->formatValue('amount', $this->attribute('amount'))]),
            'ledger_entries.updated' => __('Przeliczono: :description', ['description' => $this->attribute('description')]),
            'ledger_entries.deleted' => $this->attribute('kind') === 'payment'
                ? __('Usunięto wpłatę: :amount', ['amount' => $this->formatValue('amount', $this->attribute('amount'))])
                : __('Usunięto naliczenie: :description', ['description' => $this->attribute('description')]),

            'users.created' => __('Założono konto'),
            'users.updated' => __('Zmieniono dane konta'),
            'users.login' => __('Logowanie'),
            'users.logout' => __('Wylogowanie'),
            'users.password_changed' => __('Zmieniono hasło'),
            default => (string) $this->activity->description,
        };
    }

    public function causerName(): string
    {
        return $this->activity->causer?->name ?? __('System (automatycznie)');
    }

    /**
     * The apartment this entry concerns.
     */
    public function apartment(): ?Apartment
    {
        if ($this->activity->subject instanceof Apartment) {
            return $this->activity->subject;
        }

        $id = $this->activity->getProperty('apartment_id');

        if (! $id) {
            return null;
        }

        return static::$apartments[$id] ??= Apartment::find($id);
    }

    /**
     * Field changes of an update, as [label, old, new].
     *
     * @return list<array{label: string, old: string, new: string}>
     */
    public function changes(): array
    {
        if ($this->activity->event !== 'updated') {
            return [];
        }

        $new = $this->activity->attribute_changes?->get('attributes', []) ?? [];
        $old = $this->activity->attribute_changes?->get('old', []) ?? [];
        $labels = $this->labels();

        $changes = [];
        foreach ($new as $attribute => $value) {
            if (in_array($attribute, ['description'], true)) {
                continue;
            }

            $changes[] = [
                'label' => $labels[$attribute] ?? $attribute,
                'old' => $this->formatValue($attribute, $old[$attribute] ?? null),
                'new' => $this->formatValue($attribute, $value),
            ];
        }

        return $changes;
    }

    /**
     * @return array<string, string>
     */
    protected function labels(): array
    {
        return match ($this->activity->log_name) {
            'apartments' => Apartment::attributeLabels(),
            'leases' => Lease::attributeLabels(),
            'bills' => Bill::attributeLabels(),
            'lease_tenants' => ['first_name' => __('Imię'), 'last_name' => __('Nazwisko'), 'email' => __('E-mail'), 'phone' => __('Telefon'), 'is_primary' => __('Główny kontakt')],
            'recurring_charge_rates' => ['amount' => __('Kwota'), 'valid_from' => __('Od miesiąca')],
            'ledger_entries' => ['amount' => __('Kwota'), 'booked_on' => __('Data'), 'due_on' => __('Termin'), 'notes' => __('Notatka'), 'payment_method' => __('Sposób')],
            default => ['name' => __('Imię i nazwisko'), 'email' => __('E-mail')],
        };
    }

    protected function attribute(string $key): mixed
    {
        $changes = $this->activity->attribute_changes;

        return $changes?->get('attributes')[$key] ?? $changes?->get('old')[$key] ?? null;
    }

    protected function leaseUpdateTitle(): string
    {
        $changed = array_keys($this->activity->attribute_changes?->get('attributes', []) ?? []);

        return match (true) {
            in_array('terminated_on', $changed, true) && $this->attribute('terminated_on') !== null => __('Zakończono najem z dniem :date', ['date' => $this->formatValue('terminated_on', $this->attribute('terminated_on'))]),
            in_array('deposit_returned_amount', $changed, true) || in_array('deposit_returned_on', $changed, true) => __('Zapisano zwrot kaucji'),
            default => __('Zmieniono warunki najmu'),
        };
    }

    protected function tenantName(): string
    {
        $subject = $this->activity->subject;

        return $subject instanceof LeaseTenant
            ? $subject->full_name
            : trim($this->attribute('first_name').' '.$this->attribute('last_name'));
    }

    protected function chargeName(): string
    {
        $type = $this->attribute('type');

        return $type === ChargeType::Other->value && filled($this->attribute('name'))
            ? (string) $this->attribute('name')
            : (ChargeType::tryFrom((string) $type)?->label() ?? '');
    }

    protected function rateTitle(): string
    {
        $subject = $this->activity->subject;
        $name = $subject instanceof RecurringChargeRate ? $subject->charge?->label : null;
        $amount = (int) $this->attribute('amount');
        $month = $this->attribute('valid_from') ? CarbonImmutable::parse($this->attribute('valid_from'))->locale('pl')->isoFormat('D MMMM YYYY') : '';

        return $amount === 0
            ? __(':name: bez opłaty od :month', ['name' => $name ?? __('Opłata'), 'month' => $month])
            : __(':name: :amount od :month', ['name' => $name ?? __('Opłata'), 'amount' => $this->formatValue('amount', $amount), 'month' => $month]);
    }

    protected function formatValue(string $attribute, mixed $value): string
    {
        if ($value === null || $value === '') {
            return '—';
        }

        $currency = $this->activity->getProperty('currency') ?? Currencies::DEFAULT;

        return match (true) {
            $attribute === 'country_code' => Countries::name($value),
            $attribute === 'area' => Number::format((float) $value, maxPrecision: 2, locale: 'pl').' m²',
            in_array($attribute, self::MONEY_ATTRIBUTES, true) => Money::format((int) $value, $currency),
            in_array($attribute, self::DATE_ATTRIBUTES, true) => CarbonImmutable::parse($value)->format('d.m.Y'),
            $attribute === 'category' => BillCategory::tryFrom($value)?->label() ?? (string) $value,
            in_array($attribute, ['deposit_method', 'payment_method'], true) => PaymentMethod::tryFrom($value)?->label() ?? (string) $value,
            $attribute === 'bank_account' => BankAccount::format($value),
            $attribute === 'is_primary' => $value ? __('tak') : __('nie'),
            default => (string) $value,
        };
    }
}
