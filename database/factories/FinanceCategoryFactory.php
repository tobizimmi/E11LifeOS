<?php

namespace Database\Factories;

use App\Models\FinanceCategory;
use App\Models\Household;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends Factory<FinanceCategory>
 */
class FinanceCategoryFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'household_id' => Household::factory(),
            'user_id' => User::factory(),
            'key' => 'cat_'.Str::lower(Str::random(8)),
            'label' => fake()->randomElement(['Hobby', 'Garten', 'Nebenjob', 'Spende']),
            'icon' => fake()->randomElement(['🎨', '🌱', '💶', '🎁']),
            'group' => fake()->randomElement(['needs', 'wants', 'savings']),
        ];
    }
}
