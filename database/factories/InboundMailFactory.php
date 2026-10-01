<?php

namespace Database\Factories;

use App\Models\Household;
use App\Models\InboundMail;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<InboundMail>
 */
class InboundMailFactory extends Factory
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
            'from_address' => fake()->safeEmail(),
            'subject' => fake()->sentence(4),
            'status' => 'inbox',
        ];
    }
}
