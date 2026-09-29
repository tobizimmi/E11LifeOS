<?php

namespace Database\Factories;

use App\Models\Household;
use App\Models\Item;
use App\Models\Vehicle;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Vehicle>
 */
class VehicleFactory extends Factory
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
            'visibility' => Item::VISIBILITY_SHARED,
            'name' => fake()->randomElement(['Familienauto', 'Zweitwagen', 'Roller']),
            'make_model' => fake()->randomElement(['VW Golf', 'Skoda Octavia', 'Tesla Model 3']),
            'plate' => 'M-'.fake()->bothify('??').' '.fake()->numberBetween(100, 9999),
            'fuel' => 'petrol',
            'odometer_start' => 50000,
            'tyre_reminders' => true,
        ];
    }

    /**
     * An electric car.
     */
    public function electric(): static
    {
        return $this->state(fn () => ['fuel' => 'electric']);
    }
}
