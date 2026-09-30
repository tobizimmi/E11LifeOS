<?php

namespace Database\Factories;

use App\Models\ExpenseGroup;
use App\Models\Household;
use App\Models\Item;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<ExpenseGroup>
 */
class ExpenseGroupFactory extends Factory
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
            'title' => fake()->randomElement(['Skiurlaub', 'WG-Kasse', 'Vereinsausflug']),
            'icon' => '🧾',
            'currency' => 'EUR',
        ];
    }
}
