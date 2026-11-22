<?php

namespace Database\Factories;

use App\Models\ShoppingList;
use App\Models\ShoppingListReminder;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<ShoppingListReminder>
 */
class ShoppingListReminderFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'shopping_list_id' => ShoppingList::factory(),
            'text' => fake()->randomElement(['Pfandflaschen mitnehmen', 'Beutel nicht vergessen', 'Kundenkarte bereithalten']),
            'done_at' => null,
            'position' => 0,
        ];
    }

    public function done(): static
    {
        return $this->state(fn () => ['done_at' => now()]);
    }
}
