<?php

namespace Database\Factories;

use App\Models\Household;
use App\Models\RealEstateAnalysis;
use App\Support\RealEstate\ProfitabilityAnalysis;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<RealEstateAnalysis>
 */
class RealEstateAnalysisFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'household_id' => Household::factory(),
            'title' => 'ETW '.fake()->city(),
            'inputs' => ProfitabilityAnalysis::defaults(),
        ];
    }
}
