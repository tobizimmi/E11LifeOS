<?php

namespace Database\Factories;

use App\Models\Bookmark;
use App\Models\Household;
use App\Models\Item;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Bookmark>
 */
class BookmarkFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'household_id' => Household::factory(),
            'visibility' => Item::VISIBILITY_SHARED,
            'url' => fake()->url(),
            'title' => fake()->sentence(4),
            'description' => fake()->sentence(),
            'site_name' => fake()->domainName(),
        ];
    }

    public function privateTo(User $user): static
    {
        return $this->state(['visibility' => Item::VISIBILITY_PRIVATE, 'created_by' => $user->id]);
    }
}
