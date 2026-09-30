<?php

namespace Database\Factories;

use App\Models\InvestmentThesis;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<InvestmentThesis>
 */
class InvestmentThesisFactory extends Factory
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
            'symbol' => 'AAPL',
            'thesis' => 'Starke Marke und wachsendes Service-Geschäft.',
        ];
    }
}
