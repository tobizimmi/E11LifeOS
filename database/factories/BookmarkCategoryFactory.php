<?php

namespace Database\Factories;

use App\Models\BookmarkCategory;
use App\Models\Household;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<BookmarkCategory>
 */
class BookmarkCategoryFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'household_id' => Household::factory(),
            'name' => fake()->randomElement(['Einkäufe', 'Zeitschriften', 'Artikel', 'Reise', 'Lernen']),
            'icon' => fake()->randomElement(['🛍️', '📰', '📄', '✈️', '💡']),
            'color' => fake()->randomElement(['#2563eb', '#dc2626', '#16a34a', '#d97706']),
        ];
    }
}
