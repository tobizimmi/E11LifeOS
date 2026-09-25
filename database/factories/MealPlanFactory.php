<?php

namespace Database\Factories;

use App\Models\Household;
use App\Models\MealPlan;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<MealPlan>
 */
class MealPlanFactory extends Factory
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
            'date' => today(),
            'meal_type' => 'dinner',
            'title' => fake()->words(2, true),
        ];
    }
}
