<?php

namespace Database\Factories;

use App\Models\Quote;
use App\Models\QuoteComparison;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Quote>
 */
class QuoteFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'quote_comparison_id' => QuoteComparison::factory(),
            'vendor' => fake()->company(),
            'amount' => fake()->randomFloat(2, 500, 20000),
        ];
    }
}
