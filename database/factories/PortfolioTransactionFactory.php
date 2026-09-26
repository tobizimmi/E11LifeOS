<?php

namespace Database\Factories;

use App\Models\Portfolio;
use App\Models\PortfolioTransaction;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<PortfolioTransaction>
 */
class PortfolioTransactionFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'portfolio_id' => Portfolio::factory(),
            'symbol' => 'SAP.DE',
            'name' => 'SAP SE',
            'isin' => 'DE0007164600',
            'type' => 'buy',
            'quantity' => 10,
            'price' => 100,
            'fees' => 1,
            'currency' => 'EUR',
            'executed_on' => now()->subMonth()->toDateString(),
        ];
    }
}
