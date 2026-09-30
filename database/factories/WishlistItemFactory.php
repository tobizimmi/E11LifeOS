<?php

namespace Database\Factories;

use App\Models\Household;
use App\Models\Item;
use App\Models\WishlistItem;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<WishlistItem>
 */
class WishlistItemFactory extends Factory
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
            'title' => fake()->randomElement(['Kopfhörer', 'Rennrad', 'Espressomaschine', 'Wochenende in Wien']),
            'price' => fake()->randomFloat(2, 50, 2000),
            'priority' => 'want',
            'category' => 'other',
            'status' => 'open',
        ];
    }
}
