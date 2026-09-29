<?php

namespace Database\Factories;

use App\Models\Household;
use App\Models\Item;
use App\Models\Trip;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Trip>
 */
class TripFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        $start = fake()->dateTimeBetween('+1 week', '+3 months');

        return [
            'household_id' => Household::factory(),
            'visibility' => Item::VISIBILITY_SHARED,
            'title' => fake()->randomElement(['Sommerurlaub', 'Städtetrip', 'Skiurlaub']),
            'destination' => fake()->city(),
            'starts_on' => $start,
            'ends_on' => (clone $start)->modify('+7 days'),
        ];
    }
}
