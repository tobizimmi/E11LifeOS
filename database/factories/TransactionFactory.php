<?php

namespace Database\Factories;

use App\Models\BankAccount;
use App\Models\Transaction;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends Factory<Transaction>
 */
class TransactionFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'bank_account_id' => BankAccount::factory(),
            'household_id' => fn (array $attributes) => BankAccount::find($attributes['bank_account_id'])->household_id,
            'user_id' => fn (array $attributes) => BankAccount::find($attributes['bank_account_id'])->user_id,
            'booked_on' => fake()->dateTimeBetween('-3 months'),
            'amount' => -fake()->randomFloat(2, 1, 200),
            'counterparty' => fake()->company(),
            'purpose' => fake()->sentence(3),
            'category' => 'other',
            'import_hash' => sha1(Str::random(20)),
        ];
    }
}
