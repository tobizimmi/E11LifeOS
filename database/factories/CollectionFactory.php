<?php

namespace Database\Factories;

use App\Models\Collection;
use App\Models\Household;
use App\Models\Item;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Collection>
 */
class CollectionFactory extends Factory
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
            'name' => fake()->words(2, true),
            'icon' => null,
            'visibility' => Item::VISIBILITY_SHARED,
        ];
    }

    public function privateTo(User $user): static
    {
        return $this->state(['visibility' => Item::VISIBILITY_PRIVATE, 'created_by' => $user->id]);
    }
}
