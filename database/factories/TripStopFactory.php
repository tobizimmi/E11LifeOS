<?php

namespace Database\Factories;

use App\Models\Trip;
use App\Models\TripStop;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<TripStop>
 */
class TripStopFactory extends Factory
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
            'kind' => fake()->randomElement(TripStop::KINDS),
            'title' => fake()->randomElement(['Altstadt', 'Strand', 'Museum', 'Tapas-Bar', 'Aussichtspunkt']),
            'location' => fake()->city(),
        ];
    }

    /**
     * Not planned on a day yet: an idea.
     */
    public function idea(): static
    {
        return $this->state(['day' => null]);
    }
}
