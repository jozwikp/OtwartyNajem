<?php

namespace App\Support;

use App\Models\Apartment;
use Illuminate\Support\Number;
use Spatie\Activitylog\Models\Activity;

/**
 * Turns raw activity log entries into plain Polish sentences for the change history.
 */
class ActivityPresenter
{
    public function __construct(public Activity $activity) {}

    public function icon(): string
    {
        return match ($this->activity->event) {
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
        };
    }

    public function title(): string
    {
        $email = $this->activity->getProperty('email');
        $name = $this->activity->getProperty('name');

        return match ($this->activity->log_name.'.'.$this->activity->event) {
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
        return $this->activity->causer?->name ?? __('System');
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
        $labels = $this->activity->log_name === 'apartments'
            ? Apartment::attributeLabels()
            : ['name' => __('Imię i nazwisko'), 'email' => __('E-mail')];

        $changes = [];

        foreach ($new as $attribute => $value) {
            $changes[] = [
                'label' => $labels[$attribute] ?? $attribute,
                'old' => $this->formatValue($attribute, $old[$attribute] ?? null),
                'new' => $this->formatValue($attribute, $value),
            ];
        }

        return $changes;
    }

    protected function formatValue(string $attribute, mixed $value): string
    {
        if ($value === null || $value === '') {
            return '—';
        }

        return match ($attribute) {
            'country_code' => Countries::name($value),
            'area' => Number::format((float) $value, maxPrecision: 2, locale: 'pl').' m²',
            default => (string) $value,
        };
    }
}
