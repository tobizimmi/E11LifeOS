<?php

namespace Database\Factories;

use App\Models\Household;
use App\Models\Invoice;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Invoice>
 */
class InvoiceFactory extends Factory
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
            'status' => Invoice::DRAFT,
            'recipient' => fake()->company()."\n".fake()->streetAddress()."\n".fake()->postcode().' '.fake()->city(),
            'service_period' => 'September 2026',
            'small_business' => true,
            'net_total' => 0,
            'vat_total' => 0,
            'gross_total' => 0,
        ];
    }

    /**
     * An issued invoice with a total of $amount.
     */
    public function paid(float $amount, string $paidOn, string $number = 'RE-2026-001'): static
    {
        return $this->state(fn () => [
            'status' => Invoice::PAID, 'number' => $number, 'issued_on' => $paidOn, 'due_on' => $paidOn, 'paid_on' => $paidOn,
            'net_total' => $amount, 'vat_total' => 0, 'gross_total' => $amount,
        ]);
    }
}
