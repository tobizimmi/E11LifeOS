<?php

namespace Database\Factories;

use App\Models\Household;
use App\Models\ModuleEntitlement;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<ModuleEntitlement>
 */
class ModuleEntitlementFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'module' => 'trips',
            'household_id' => Household::factory(),
            'access' => ModuleEntitlement::GRANTED,
            'source' => ModuleEntitlement::SOURCE_ADMIN,
        ];
    }

    public function blocked(): static
    {
        return $this->state(['access' => ModuleEntitlement::BLOCKED]);
    }

    public function license(): static
    {
        return $this->state(['source' => ModuleEntitlement::SOURCE_LICENSE, 'starts_at' => now()->subDay(), 'ends_at' => now()->addYear()]);
    }
}
