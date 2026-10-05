<?php

namespace Database\Factories;

use App\Models\RoadmapMilestone;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<RoadmapMilestone>
 */
class RoadmapMilestoneFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'title' => fake()->words(3, true),
            'version' => '1.'.fake()->numberBetween(40, 60),
            'status' => 'planned',
        ];
    }
}
