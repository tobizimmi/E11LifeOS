<?php

namespace Database\Factories;

use App\Models\Expense;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Expense>
 */
class ExpenseFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'title' => fake()->randomElement(['Einkauf', 'Tanken', 'Restaurant']),
            'amount' => fake()->randomFloat(2, 5, 200),
            'spent_on' => today(),
            'category' => 'other',
            'split_mode' => 'equal',
        ];
    }
}
