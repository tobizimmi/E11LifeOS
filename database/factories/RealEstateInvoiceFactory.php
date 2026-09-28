<?php

namespace Database\Factories;

use App\Models\RealEstate;
use App\Models\RealEstateInvoice;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<RealEstateInvoice>
 */
class RealEstateInvoiceFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'real_estate_id' => RealEstate::factory(),
            'vendor' => fake()->company(),
            'invoice_date' => '2026-09-01',
            'amount' => 1000,
            'labor_amount' => 0,
        ];
    }
}
