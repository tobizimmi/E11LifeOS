<?php

namespace Database\Factories;

use App\Models\Household;
use App\Models\Item;
use App\Models\Recipe;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Recipe>
 */
class RecipeFactory extends Factory
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
            'visibility' => Item::VISIBILITY_SHARED,
            'title' => fake()->words(3, true),
            'description' => fake()->sentence(),
            'ingredients' => [
                ['amount' => '200', 'unit' => 'g', 'name' => 'Mehl'],
                ['amount' => '2', 'unit' => '', 'name' => 'Eier'],
            ],
            'instructions' => [['text' => 'Alles verrühren.'], ['text' => 'Backen.']],
            'prep_time' => 10,
            'cook_time' => 20,
            'servings' => 2,
        ];
    }

    public function privateTo(User $user): static
    {
        return $this->state(['visibility' => Item::VISIBILITY_PRIVATE, 'created_by' => $user->id]);
    }
}
