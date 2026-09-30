<?php

namespace Database\Factories;

use App\Models\Household;
use App\Models\RealEstate;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<RealEstate>
 */
class RealEstateFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'household_id' => Household::factory(),
            'name' => fake()->streetName(),
            'city' => fake()->city(),
            'state' => 'NW',
            'usage' => RealEstate::OWNER,
            'status' => 'planning',
            'purchase_price' => 400000,
            'land_share' => 20,
            'construction_year' => 1985,
        ];
    }

    /**
     * A let property.
     */
    public function rental(int $monthlyRent = 1200): static
    {
        return $this->state(fn () => ['usage' => RealEstate::RENTAL, 'monthly_rent' => $monthlyRent]);
    }
}
