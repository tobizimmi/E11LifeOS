<?php

namespace Database\Factories;

use App\Models\Household;
use App\Models\Item;
use App\Models\Place;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Place>
 */
class PlaceFactory extends Factory
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
            'name' => fake()->randomElement(['Trattoria Roma', 'Café Kranzler', 'Zur Linde', 'Sushi Bar Kyoto']),
            'category' => 'restaurant',
            'city' => fake()->randomElement(['München', 'Berlin', 'Hamburg']),
        ];
    }

    public function visited(int $rating = 4): static
    {
        return $this->state(['visited' => true, 'rating' => $rating, 'visit_count' => 1, 'last_visited_on' => today()]);
    }
}
