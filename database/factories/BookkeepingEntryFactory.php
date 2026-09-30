<?php

namespace Database\Factories;

use App\Models\BookkeepingEntry;
use App\Models\Household;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<BookkeepingEntry>
 */
class BookkeepingEntryFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'user_id' => User::factory(),
            'household_id' => Household::factory(),
            'kind' => BookkeepingEntry::EXPENSE,
            'booked_on' => fake()->dateTimeBetween('-6 months'),
            'description' => fake()->sentence(3),
            'counterparty' => fake()->company(),
            'category' => 'office',
            'amount' => fake()->randomFloat(2, 5, 300),
            'vat_amount' => 0,
        ];
    }
}
