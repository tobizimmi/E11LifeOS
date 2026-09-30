<?php

namespace Database\Factories;

use App\Models\Contact;
use App\Models\Household;
use App\Models\Item;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Contact>
 */
class ContactFactory extends Factory
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
            'name' => fake()->name(),
            'relation' => fake()->randomElement(['Freundin', 'Bruder', 'Kollege', 'Oma']),
            'birthday' => fake()->dateTimeBetween('-70 years', '-5 years')->format('Y-m-d'),
            'remind_days' => 7,
        ];
    }
}
