<?php

namespace Database\Factories;

use App\Models\Household;
use App\Models\Item;
use App\Models\Space;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Space>
 */
class SpaceFactory extends Factory
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
            'visibility' => Item::VISIBILITY_SHARED,
            'name' => fake()->randomElement(['Arbeit', 'Zuhause', 'Verein']),
            'icon' => '🗂️',
            'color' => 'indigo',
        ];
    }
}
