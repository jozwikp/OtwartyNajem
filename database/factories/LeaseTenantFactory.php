<?php

namespace Database\Factories;

use App\Models\LeaseTenant;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<LeaseTenant>
 */
class LeaseTenantFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'first_name' => fake()->firstName(),
            'last_name' => fake()->lastName(),
            'email' => fake()->safeEmail(),
            'phone' => fake()->phoneNumber(),
            'is_primary' => true,
        ];
    }
}
