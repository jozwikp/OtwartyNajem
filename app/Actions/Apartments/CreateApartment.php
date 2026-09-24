<?php

namespace App\Actions\Apartments;

use App\Models\Apartment;
use App\Models\User;
use Illuminate\Support\Facades\DB;

class CreateApartment
{
    /**
     * @param  array{label: string, street: string, unit_number: ?string, postal_code: string, city: string, country_code: string, area: float|string}  $data
     */
    public function handle(User $user, array $data): Apartment
    {
        return DB::transaction(function () use ($user, $data) {
            $apartment = new Apartment($data);
            $apartment->created_by = $user->id;
            $apartment->save();

            $apartment->owners()->attach($user->id, ['added_by' => $user->id]);

            return $apartment;
        });
    }
}
