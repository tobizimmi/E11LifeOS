<?php

namespace Database\Factories;

use App\Models\Household;
use App\Models\Item;
use App\Models\Learning;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Learning>
 */
class LearningFactory extends Factory
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
            'visibility' => Item::VISIBILITY_PRIVATE,
            'title' => fake()->randomElement(['Erst schlafen, dann entscheiden', 'Angebote immer vergleichen', 'Nein sagen ist erlaubt']),
            'kind' => 'experience',
            'area' => 'self',
            'due_on' => today()->addDay(),
        ];
    }
}
