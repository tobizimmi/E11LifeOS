<?php

namespace Database\Factories;

use App\Models\BankAccount;
use App\Models\Household;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<BankAccount>
 */
class BankAccountFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'household_id' => Household::factory(),
            'user_id' => User::factory(),
            'name' => fake()->randomElement(['Girokonto', 'Gemeinschaftskonto', 'Kreditkarte']),
            'iban' => null,
        ];
    }
}
