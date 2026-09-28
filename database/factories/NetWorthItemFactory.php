<?php

namespace Database\Factories;

use App\Models\NetWorthItem;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<NetWorthItem>
 */
class NetWorthItemFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'user_id' => User::factory(),
            'name' => fake()->randomElement(['Bargeld', 'Auto', 'Tagesgeld']),
            'kind' => NetWorthItem::ASSET,
            'value' => fake()->randomFloat(2, 100, 20000),
        ];
    }

    public function liability(): static
    {
        return $this->state(fn () => ['name' => 'Privatkredit', 'kind' => NetWorthItem::LIABILITY]);
    }
}
