<?php

namespace Database\Factories;

use App\Models\Household;
use App\Models\RecurringInvoice;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<RecurringInvoice>
 */
class RecurringInvoiceFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'user_id' => User::factory(),
            'household_id' => Household::factory(),
            'name' => 'Wartungsvertrag',
            'recipient' => fake()->company(),
            'items' => [['description' => 'Wartung', 'quantity' => 1, 'unit' => null, 'unit_price' => 100, 'discount_percent' => 0, 'vat_rate' => 0]],
            'frequency' => 'monthly',
            'next_run_on' => now()->toDateString(),
        ];
    }
}
