<?php

namespace Database\Factories;

use App\Models\Household;
use App\Models\TimeEntry;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<TimeEntry>
 */
class TimeEntryFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'user_id' => User::factory(),
            'household_id' => Household::factory(),
            'description' => fake()->sentence(3),
            'started_at' => now()->subHours(2),
            'ended_at' => now()->subHour(),
            'minutes' => 60,
            'hourly_rate' => 80,
        ];
    }
}
