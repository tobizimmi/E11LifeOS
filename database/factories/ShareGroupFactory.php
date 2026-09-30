<?php

namespace Database\Factories;

use App\Models\ShareGroup;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<ShareGroup>
 */
class ShareGroupFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'name' => 'Freunde',
            'icon' => '👥',
            'created_by' => User::factory(),
        ];
    }

    /**
     * The creator joins as owner.
     */
    public function configure(): static
    {
        return $this->afterCreating(fn (ShareGroup $group) => $group->members()->firstOrCreate(['user_id' => $group->created_by], ['role' => ShareGroup::ROLE_OWNER]));
    }
}
