<?php

namespace Database\Factories;

use App\Models\Household;
use App\Models\Item;
use App\Models\QuoteComparison;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<QuoteComparison>
 */
class QuoteComparisonFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'household_id' => Household::factory(),
            'visibility' => Item::VISIBILITY_SHARED,
            'title' => fake()->words(2, true),
        ];
    }
}
