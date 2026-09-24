<?php

namespace Database\Factories;

use App\Models\Apartment;
use App\Models\ApartmentInvitation;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends Factory<ApartmentInvitation>
 */
class ApartmentInvitationFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'apartment_id' => Apartment::factory(),
            'email' => fake()->unique()->safeEmail(),
            'token' => ApartmentInvitation::hashToken(Str::random(48)),
            'expires_at' => now()->addDays(ApartmentInvitation::VALID_DAYS),
        ];
    }

    /**
     * Use a known plain token, so tests can open the invitation link.
     */
    public function withPlainToken(string $plainToken): static
    {
        return $this->state(['token' => ApartmentInvitation::hashToken($plainToken)]);
    }

    public function expired(): static
    {
        return $this->state(['expires_at' => now()->subDay()]);
    }
}
