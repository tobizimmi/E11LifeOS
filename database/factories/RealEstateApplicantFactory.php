<?php

namespace Database\Factories;

use App\Models\RealEstate;
use App\Models\RealEstateApplicant;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<RealEstateApplicant>
 */
class RealEstateApplicantFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'real_estate_id' => RealEstate::factory()->rental(),
            'status' => 'new',
            'name' => fake()->name(),
            'email' => fake()->safeEmail(),
            'current_address' => fake()->address(),
            'occupation' => 'Ingenieurin',
            'net_income' => 3500,
            'persons' => 2,
            'children' => 0,
            'consent_at' => now(),
        ];
    }

    public function rejected(): static
    {
        return $this->state(['status' => 'rejected']);
    }
}
