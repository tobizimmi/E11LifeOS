<?php

namespace Database\Factories;

use App\Models\Habit;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Habit>
 */
class HabitFactory extends Factory
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
            'name' => fake()->randomElement(['Sport', 'Lesen', 'Meditieren', 'Spazierengehen']),
            'icon' => '✅',
            'type' => Habit::CHECK,
            'target' => 1,
            'schedule' => Habit::DAILY,
        ];
    }

    /**
     * Counted towards a daily target, e.g. 8 glasses of water.
     */
    public function counted(int $target = 8, string $unit = 'Gläser'): static
    {
        return $this->state(['type' => Habit::COUNT, 'target' => $target, 'unit' => $unit]);
    }
}
