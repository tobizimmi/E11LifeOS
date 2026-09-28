<?php

namespace Database\Factories;

use App\Models\RealEstate;
use App\Models\RealEstateTenant;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<RealEstateTenant>
 */
class RealEstateTenantFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'real_estate_id' => RealEstate::factory()->rental(),
            'name' => fake()->name(),
            'persons' => 2,
            'area' => 70,
            'move_in_on' => '2025-01-01',
            'cold_rent' => 800,
            'utilities_advance' => 200,
            'deposit' => 2400,
        ];
    }
}
