<?php

namespace Database\Factories;

use App\Models\Household;
use App\Models\Item;
use App\Models\LoyaltyCard;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<LoyaltyCard>
 */
class LoyaltyCardFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'household_id' => Household::factory(),
            'visibility' => Item::VISIBILITY_SHARED,
            'name' => fake()->randomElement(['REWE', 'DM', 'Payback', 'IKEA Family', 'Lidl Plus', 'DeutschlandCard']),
            'code' => (string) fake()->numerify('#############'),
            'code_format' => 'EAN_13',
            'color' => fake()->randomElement(['#2563eb', '#dc2626', '#16a34a', '#d97706', null]),
        ];
    }

    public function privateTo(User $user): static
    {
        return $this->state(['visibility' => Item::VISIBILITY_PRIVATE, 'created_by' => $user->id]);
    }

    public function qr(): static
    {
        return $this->state(['code_format' => 'QR_CODE', 'code' => fake()->url()]);
    }
}
