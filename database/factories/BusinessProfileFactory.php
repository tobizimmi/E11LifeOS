<?php

namespace Database\Factories;

use App\Models\BusinessProfile;
use App\Models\Household;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<BusinessProfile>
 */
class BusinessProfileFactory extends Factory
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
            'company_name' => fake()->company(),
            'owner_name' => fake()->name(),
            'street' => fake()->streetAddress(),
            'postal_code' => fake()->postcode(),
            'city' => fake()->city(),
            'email' => fake()->safeEmail(),
            'tax_number' => '12/345/67890',
            'small_business' => true,
            'default_vat_rate' => 19,
            'iban' => 'DE02120300000000202051',
            'invoice_prefix' => 'RE-',
            'payment_days' => 14,
        ];
    }

    /**
     * Charges VAT (no small business rule).
     */
    public function withVat(): static
    {
        return $this->state(fn () => ['small_business' => false]);
    }
}
