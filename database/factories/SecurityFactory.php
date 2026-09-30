<?php

namespace Database\Factories;

use App\Models\Security;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Security>
 */
class SecurityFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'symbol' => strtoupper(fake()->unique()->lexify('???')).'.DE',
            'name' => fake()->company(),
            'isin' => 'DE'.fake()->unique()->numerify('##########'),
            'exchange' => 'XETRA',
            'asset_type' => 'stock',
            'currency' => 'EUR',
            'last_price' => 100,
            'previous_close' => 98,
            'price_updated_at' => now(),
        ];
    }
}
