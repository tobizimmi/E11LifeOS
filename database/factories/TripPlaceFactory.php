<?php

namespace Database\Factories;

use App\Models\Trip;
use App\Models\TripPlace;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<TripPlace>
 */
class TripPlaceFactory extends Factory
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
            'osm_id' => 'node/'.fake()->unique()->numberBetween(1, 99999999),
            'name' => fake()->streetName(),
            'kind' => 'sight',
            'category' => 'attraction',
            'address' => fake()->streetAddress(),
            'lat' => fake()->latitude(47.4, 47.6),
            'lng' => fake()->longitude(19.0, 19.1),
            'info' => null,
        ];
    }
}
