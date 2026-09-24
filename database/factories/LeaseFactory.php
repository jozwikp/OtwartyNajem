<?php

namespace Database\Factories;

use App\Models\Apartment;
use App\Models\Lease;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Lease>
 */
class LeaseFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'apartment_id' => Apartment::factory(),
            'starts_on' => now()->startOfMonth()->subMonths(2)->toDateString(),
            'ends_on' => null,
            'currency' => 'PLN',
            'payment_due_day' => 10,
            'bank_account' => 'PL61109010140000071219812874',
        ];
    }
}
