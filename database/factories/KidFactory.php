<?php

namespace Database\Factories;

use App\Models\Household;
use App\Models\Kid;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Kid>
 */
class KidFactory extends Factory
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
            'name' => fake()->firstName(),
            'emoji' => '🧒',
            'weekly_allowance' => 5,
            'allowance_day' => 6,
        ];
    }
}
