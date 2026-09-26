<?php

namespace Database\Factories;

use App\Models\Household;
use App\Models\Item;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Item>
 */
class ItemFactory extends Factory
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
            'type' => Item::TYPE_NOTE,
            'visibility' => Item::VISIBILITY_SHARED,
            'title' => fake()->sentence(3),
            'content' => ['body' => fake()->paragraph()],
            'done' => false,
            'position' => 0,
        ];
    }

    public function note(): static
    {
        return $this->state(['type' => Item::TYPE_NOTE]);
    }

    public function todo(): static
    {
        return $this->state(['type' => Item::TYPE_TODO, 'content' => null]);
    }

    public function inbox(): static
    {
        return $this->state(['type' => Item::TYPE_INBOX, 'content' => null]);
    }

    /**
     * A private item must belong to someone, so the creator is required.
     */
    public function privateTo(User $user): static
    {
        return $this->state([
            'visibility' => Item::VISIBILITY_PRIVATE,
            'created_by' => $user->id,
        ]);
    }
}
