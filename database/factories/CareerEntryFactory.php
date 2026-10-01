<?php

namespace Database\Factories;

use App\Models\CareerEntry;
use App\Models\Household;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<CareerEntry>
 */
class CareerEntryFactory extends Factory
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
            'type' => 'job',
            'title' => fake()->jobTitle(),
            'organization' => fake()->company(),
            'starts_on' => fake()->dateTimeBetween('-10 years', '-1 year'),
        ];
    }
}
