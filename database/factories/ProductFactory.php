<?php

namespace Database\Factories;

use App\Models\Household;
use App\Models\Product;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Product>
 */
class ProductFactory extends Factory
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
            'name' => fake()->words(2, true),
            'unit' => 'Std.',
            'unit_price' => fake()->randomFloat(2, 20, 120),
            'vat_rate' => 19,
        ];
    }
}
