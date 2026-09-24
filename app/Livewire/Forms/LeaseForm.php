<?php

namespace App\Livewire\Forms;

use App\Enums\PaymentMethod;
use App\Models\Lease;
use App\Support\BankAccount;
use App\Support\Currencies;
use App\Support\Money;
use Closure;
use Illuminate\Validation\Rule;
use Livewire\Form;

/**
 * Contract terms and deposit – shared by the "new lease" wizard and the edit page.
 */
class LeaseForm extends Form
{
    public string $starts_on = '';

    public bool $open_ended = true;

    public string $ends_on = '';

    public string $payment_due_day = '10';

    public string $currency = Currencies::DEFAULT;

    public string $bank_account = '';

    public string $notes = '';

    public bool $has_deposit = false;

    public string $deposit_amount = '';

    public string $deposit_method = 'transfer';

    public function fillFrom(Lease $lease): void
    {
        $this->starts_on = $lease->starts_on->toDateString();
        $this->open_ended = $lease->ends_on === null;
        $this->ends_on = (string) $lease->ends_on?->toDateString();
        $this->payment_due_day = (string) $lease->payment_due_day;
        $this->currency = $lease->currency;
        $this->bank_account = BankAccount::format($lease->bank_account);
        $this->notes = (string) $lease->notes;
        $this->has_deposit = $lease->deposit_amount !== null;
        $this->deposit_amount = Money::toInput($lease->deposit_amount);
        $this->deposit_method = $lease->deposit_method?->value ?? 'transfer';
    }

    /**
     * @return array<string, mixed>
     */
    public function termsRules(): array
    {
        return [
            'starts_on' => ['required', 'date'],
            'ends_on' => $this->open_ended ? ['nullable'] : ['required', 'date', 'after_or_equal:form.starts_on'],
            'payment_due_day' => ['required', 'integer', 'between:1,31'],
            'currency' => ['required', Rule::in(Currencies::codes())],
            'bank_account' => ['nullable', 'string', function (string $attribute, mixed $value, Closure $fail) {
                if (filled($value) && ! BankAccount::isValid(BankAccount::normalize($value))) {
                    $fail(__('Ten numer konta wygląda na niepoprawny. Sprawdź, czy nie brakuje cyfry.'));
                }
            }],
            'notes' => ['nullable', 'string', 'max:5000'],
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function depositRules(): array
    {
        return [
            'deposit_amount' => $this->has_deposit ? ['required', static::moneyRule()] : ['nullable'],
            'deposit_method' => $this->has_deposit ? ['required', Rule::enum(PaymentMethod::class)] : ['nullable'],
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [...$this->termsRules(), ...$this->depositRules()];
    }

    /**
     * @return array<string, string>
     */
    public function validationAttributes(): array
    {
        return [
            'starts_on' => __('początek umowy'),
            'ends_on' => __('koniec umowy'),
            'payment_due_day' => __('termin płatności'),
            'currency' => __('waluta'),
            'bank_account' => __('numer konta'),
            'deposit_amount' => __('kwota kaucji'),
            'deposit_method' => __('sposób wpłaty kaucji'),
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'ends_on.after_or_equal' => __('Koniec umowy nie może być wcześniej niż jej początek.'),
            'payment_due_day.between' => __('Wpisz dzień miesiąca od 1 do 31.'),
        ];
    }

    public static function moneyRule(): Closure
    {
        return function (string $attribute, mixed $value, Closure $fail) {
            if (Money::parse((string) $value) === null) {
                $fail(__('Wpisz kwotę, np. 2500 albo 2500,50.'));
            }
        };
    }

    /**
     * @return array<string, mixed>
     */
    public function data(): array
    {
        return [
            'starts_on' => $this->starts_on,
            'ends_on' => $this->open_ended ? null : $this->ends_on,
            'payment_due_day' => (int) $this->payment_due_day,
            'currency' => $this->currency,
            'bank_account' => filled($this->bank_account) ? BankAccount::normalize($this->bank_account) : null,
            'notes' => filled($this->notes) ? trim($this->notes) : null,
            'deposit_amount' => $this->has_deposit ? Money::parse($this->deposit_amount) : null,
            'deposit_method' => $this->has_deposit ? PaymentMethod::from($this->deposit_method) : null,
        ];
    }
}
