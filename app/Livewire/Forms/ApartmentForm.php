<?php

namespace App\Livewire\Forms;

use App\Models\Apartment;
use App\Support\Countries;
use Illuminate\Validation\Rule;
use Livewire\Form;

class ApartmentForm extends Form
{
    public string $street = '';

    public string $unit_number = '';

    public string $postal_code = '';

    public string $city = '';

    public string $country_code = 'PL';

    public string $area = '';

    public string $label = '';

    public function fillFrom(Apartment $apartment): void
    {
        $this->street = $apartment->street;
        $this->unit_number = (string) $apartment->unit_number;
        $this->postal_code = $apartment->postal_code;
        $this->city = $apartment->city;
        $this->country_code = $apartment->country_code;
        $this->area = str_replace('.', ',', rtrim(rtrim((string) $apartment->area, '0'), '.'));
        $this->label = $apartment->label;
    }

    /**
     * @return array<string, mixed>
     */
    public function addressRules(): array
    {
        return [
            'street' => ['required', 'string', 'max:255'],
            'unit_number' => ['nullable', 'string', 'max:20'],
            'postal_code' => $this->country_code === 'PL'
                ? ['required', 'regex:/^\d{2}-\d{3}$/']
                : ['required', 'string', 'max:12'],
            'city' => ['required', 'string', 'max:255'],
            'country_code' => ['required', Rule::in(Countries::codes())],
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function detailsRules(): array
    {
        return [
            'area' => ['required', 'numeric', 'gt:0', 'max:100000'],
            'label' => ['required', 'string', 'max:100'],
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [...$this->addressRules(), ...$this->detailsRules()];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'postal_code.regex' => __('Wpisz kod pocztowy w formacie 00-000.'),
            'area.numeric' => __('Wpisz metraż jako liczbę, np. 48 albo 48,5.'),
            'area.gt' => __('Metraż musi być większy od zera.'),
        ];
    }

    /**
     * @return array<string, string>
     */
    public function validationAttributes(): array
    {
        return [
            'street' => __('ulica i numer budynku'),
            'unit_number' => __('numer lokalu'),
            'postal_code' => __('kod pocztowy'),
            'city' => __('miasto'),
            'country_code' => __('kraj'),
            'area' => __('metraż'),
            'label' => __('nazwa'),
        ];
    }

    /**
     * Clean up what people type: trim spaces, accept "48,5" and "02512".
     */
    public function normalize(): void
    {
        foreach (['street', 'unit_number', 'postal_code', 'city', 'label'] as $field) {
            $this->{$field} = trim(preg_replace('/\s+/', ' ', $this->{$field}));
        }

        $this->area = str_replace([' ', ','], ['', '.'], trim($this->area));

        if ($this->country_code === 'PL' && preg_match('/^(\d{2})\s?-?\s?(\d{3})$/', $this->postal_code, $m)) {
            $this->postal_code = $m[1].'-'.$m[2];
        }
    }

    public function suggestedLabel(): string
    {
        return Apartment::suggestLabel($this->street, $this->unit_number, $this->city);
    }

    /**
     * @return array{label: string, street: string, unit_number: ?string, postal_code: string, city: string, country_code: string, area: string}
     */
    public function data(): array
    {
        return [
            'label' => $this->label,
            'street' => $this->street,
            'unit_number' => $this->unit_number !== '' ? $this->unit_number : null,
            'postal_code' => $this->postal_code,
            'city' => $this->city,
            'country_code' => $this->country_code,
            'area' => $this->area,
        ];
    }
}
