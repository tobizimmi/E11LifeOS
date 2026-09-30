<?php

namespace Database\Factories;

use App\Models\User;
use App\Models\WorkDay;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<WorkDay>
 */
class WorkDayFactory extends Factory
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
            'date' => today(),
            'type' => 'work',
            'starts_at' => '08:00',
            'ends_at' => '16:30',
            'break_minutes' => 30,
        ];
    }
}
