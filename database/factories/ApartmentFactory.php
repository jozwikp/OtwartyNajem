<?php

namespace Database\Factories;

use App\Models\Apartment;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Apartment>
 */
class ApartmentFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        $street = fake()->streetName().' '.fake()->buildingNumber();
        $unit = fake()->optional(0.8)->numberBetween(1, 120);
        $city = fake()->city();

        return [
            'label' => Apartment::suggestLabel($street, $unit ? (string) $unit : null, $city),
            'street' => $street,
            'unit_number' => $unit ? (string) $unit : null,
            'postal_code' => fake()->numerify('##-###'),
            'city' => $city,
            'country_code' => 'PL',
            'area' => fake()->randomFloat(2, 18, 140),
        ];
    }

    /**
     * Attach the given user as the owner who created the apartment.
     */
    public function ownedBy(User $user): static
    {
        return $this->state(['created_by' => $user->id])
            ->afterCreating(fn (Apartment $apartment) => $apartment->owners()->attach($user->id, ['added_by' => $user->id]));
    }
}
