<?php

namespace Database\Factories;

use App\Models\Trip;
use App\Models\TripFeedback;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<TripFeedback>
 */
class TripFeedbackFactory extends Factory
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
            'author_name' => fake()->firstName(),
            'type' => TripFeedback::COMMENT,
            'body' => fake()->sentence(),
        ];
    }

    public function suggestion(): static
    {
        return $this->state(fn () => [
            'type' => TripFeedback::SUGGESTION,
            'title' => fake()->words(3, true),
            'status' => TripFeedback::PENDING,
        ]);
    }
}
