<?php

namespace Database\Factories;

use App\Models\Household;
use App\Models\Meter;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Meter>
 */
class MeterFactory extends Factory
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
            'name' => 'Strom',
            'kind' => 'electricity',
            'price_per_unit' => 0.32,
            'base_fee_monthly' => 12.5,
            'monthly_advance' => 90,
        ];
    }
}
