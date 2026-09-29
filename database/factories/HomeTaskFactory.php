<?php

namespace Database\Factories;

use App\Models\HomeTask;
use App\Models\Household;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<HomeTask>
 */
class HomeTaskFactory extends Factory
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
            'key' => HomeTask::CUSTOM,
            'title' => fake()->randomElement(['Heizung warten', 'Rauchmelder testen', 'Dachrinne reinigen']),
            'icon' => '🔧',
            'interval_months' => 12,
            'next_due_on' => fake()->dateTimeBetween('now', '+6 months')->format('Y-m-d'),
            'remind_days' => 7,
        ];
    }
}
