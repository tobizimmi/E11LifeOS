<?php

namespace Database\Factories;

use App\Models\Vehicle;
use App\Models\VehicleLog;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<VehicleLog>
 */
class VehicleLogFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'vehicle_id' => Vehicle::factory(),
            'kind' => 'fuel',
            'date' => fake()->dateTimeBetween('-3 months')->format('Y-m-d'),
            'odometer' => fake()->numberBetween(50000, 60000),
            'amount' => fake()->randomFloat(2, 40, 90),
            'quantity' => fake()->randomFloat(2, 25, 50),
        ];
    }

    /**
     * A trip for the logbook.
     */
    public function trip(string $purpose = 'business', int $distance = 50): static
    {
        return $this->state(fn () => ['kind' => 'trip', 'purpose' => $purpose, 'distance_km' => $distance, 'amount' => null, 'quantity' => null, 'destination' => fake()->city()]);
    }
}
