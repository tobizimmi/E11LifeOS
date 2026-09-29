<?php

namespace Database\Factories;

use App\Models\CashflowEntry;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<CashflowEntry>
 */
class CashflowEntryFactory extends Factory
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
            'kind' => CashflowEntry::INCOME,
            'category' => 'earned',
            'label' => 'Gehalt',
            'amount' => 2000,
            'value' => 0,
            'productive' => true,
        ];
    }

    public function expense(float $amount = 500, string $category = 'living'): static
    {
        return $this->state(fn () => ['kind' => CashflowEntry::EXPENSE, 'category' => $category, 'label' => 'Ausgabe', 'amount' => $amount]);
    }

    public function asset(float $value, float $monthlyIncome = 0, string $category = 'stocks', bool $productive = true): static
    {
        return $this->state(fn () => ['kind' => CashflowEntry::ASSET, 'category' => $category, 'label' => 'Vermögenswert', 'value' => $value, 'amount' => $monthlyIncome, 'productive' => $productive]);
    }

    public function liability(float $value, float $monthlyPayment = 0, string $category = 'consumer', bool $productive = false): static
    {
        return $this->state(fn () => ['kind' => CashflowEntry::LIABILITY, 'category' => $category, 'label' => 'Kredit', 'value' => $value, 'amount' => $monthlyPayment, 'productive' => $productive]);
    }
}
