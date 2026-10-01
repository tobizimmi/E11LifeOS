<?php

namespace Database\Factories;

use App\Models\Item;
use App\Models\PageRevision;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<PageRevision>
 */
class PageRevisionFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'item_id' => Item::factory(),
            'title' => fake()->words(3, true),
            'body' => fake()->paragraph(),
        ];
    }
}
