<?php

namespace Database\Factories;

use App\Models\Trip;
use App\Models\TripBooking;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<TripBooking>
 */
class TripBookingFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'trip_id' => Trip::factory(),
            'kind' => 'hotel',
            'title' => fake()->company(),
            'cost' => fake()->randomFloat(2, 50, 900),
        ];
    }
}
