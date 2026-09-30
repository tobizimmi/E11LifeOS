<?php

namespace Database\Factories;

use App\Models\User;
use App\Models\WorkProfile;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<WorkProfile>
 */
class WorkProfileFactory extends Factory
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
            'weekly_hours' => 40,
            'work_days' => [1, 2, 3, 4, 5],
            'vacation_days' => 30,
            'state' => 'BY',
        ];
    }
}
