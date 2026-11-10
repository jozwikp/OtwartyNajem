<?php

namespace Tests\Feature;

use App\Actions\Leases\CreateLease;
use App\Enums\ChargeType;
use App\Models\Apartment;
use App\Models\User;
use App\Support\Money;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

class DashboardTest extends TestCase
{
    use RefreshDatabase;

    public function test_guests_are_redirected_to_the_login_page(): void
    {
        $response = $this->get(route('dashboard'));
        $response->assertRedirect(route('login'));
    }

    public function test_authenticated_users_can_visit_the_dashboard(): void
    {
        $user = User::factory()->create();
        $this->actingAs($user);

        $response = $this->get(route('dashboard'));
        $response->assertOk();
    }

    public function test_the_rent_tile_sums_only_the_rent_of_active_leases(): void
    {
        $this->travelTo(CarbonImmutable::parse('2026-09-24'));

        $user = User::factory()->create();
        $this->actingAs($user);

        $active = Apartment::factory()->ownedBy($user)->create();
        app(CreateLease::class)->handle(
            $active,
            $user,
            ['starts_on' => '2026-09-01', 'ends_on' => null, 'currency' => 'PLN', 'payment_due_day' => 10],
            [['first_name' => 'Jan', 'last_name' => 'Kowalski', 'email' => 'jan@example.com', 'phone' => null]],
            [
                ['type' => ChargeType::Rent, 'name' => null, 'amount' => 250000],
                ['type' => ChargeType::AdminFee, 'name' => null, 'amount' => 60000],
            ],
        );

        $ended = Apartment::factory()->ownedBy($user)->create();
        app(CreateLease::class)->handle(
            $ended,
            $user,
            ['starts_on' => '2026-01-01', 'ends_on' => '2026-08-31', 'currency' => 'PLN', 'payment_due_day' => 10],
            [['first_name' => 'Ola', 'last_name' => 'Nowak', 'email' => 'ola@example.com', 'phone' => null]],
            [['type' => ChargeType::Rent, 'name' => null, 'amount' => 999999]],
        );

        // Only the active lease's 2500,00 zł rent counts; its admin fee and the ended lease's rent are excluded.
        $component = Livewire::test('pages::dashboard');

        expect($component->instance()->monthlyRent)->toBe(Money::format(250000, 'PLN'));

        $component->assertSee(Money::format(250000, 'PLN'));
    }
}
