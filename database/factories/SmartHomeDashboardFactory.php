<?php

namespace Database\Factories;

use App\Models\Household;
use App\Models\SmartHomeDashboard;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<SmartHomeDashboard>
 */
class SmartHomeDashboardFactory extends Factory
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
            'name' => 'Wohnzimmer',
            'icon' => '🛋️',
            'personal' => false,
            'position' => 0,
        ];
    }
}
